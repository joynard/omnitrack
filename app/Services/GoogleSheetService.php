<?php

namespace App\Services;

use App\Enums\ItemStatus;
use App\Models\Project;
use App\Models\SheetSource;
use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ingests project rows from a Google Sheet into the `projects` table.
 *
 * Design rule from the PRD: a missing or unusable credentials file must never
 * raise a fatal exception. Every failure path returns a status array instead,
 * so scheduled syncs and the UI stay healthy without Google configured.
 */
class GoogleSheetService
{
    public const STATUS_OK = 'ok';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public function credentialsPath(): string
    {
        return (string) config('services.google.credentials_path');
    }

    public function credentialsPresent(): bool
    {
        $path = $this->credentialsPath();

        return $path !== '' && is_file($path) && is_readable($path);
    }

    /**
     * Resolve (or lazily create) the SheetSource registration for a sync run.
     */
    public function defaultSource(): SheetSource
    {
        $spreadsheetId = (string) config('services.google.spreadsheet_id');

        return SheetSource::firstOrCreate(
            ['spreadsheet_id' => $spreadsheetId],
            [
                'name' => (string) config('services.google.sheet_name', 'Workspace Master Tracker'),
                'sheet_range' => (string) config('services.google.sheet_range', 'Sheet1!A2:H'),
                'is_active' => true,
            ]
        );
    }

    /**
     * Run a full ingestion pass.
     *
     * @return array{status: string, message: string, created: int, updated: int, skipped: int, rows: int}
     */
    public function sync(?SheetSource $source = null): array
    {
        // --- Graceful degradation: credentials not injected yet -------------
        if (! $this->credentialsPresent()) {
            Log::warning('Google credentials file missing. Ingestion skipped.', [
                'expected_path' => $this->credentialsPath(),
            ]);

            return $this->result(self::STATUS_SKIPPED, 'Credentials not injected yet');
        }

        $source ??= $this->defaultSource();

        if (! $source->is_active) {
            return $this->result(self::STATUS_SKIPPED, 'Sheet source is inactive');
        }

        try {
            $rows = $this->fetchRows($source);
        } catch (Throwable $e) {
            Log::error('Google Sheets fetch failed.', [
                'sheet_source_id' => $source->id,
                'spreadsheet_id' => $source->spreadsheet_id,
                'exception' => $e->getMessage(),
            ]);

            return $this->result(self::STATUS_FAILED, 'Fetch failed: '.$e->getMessage());
        }

        if ($rows === []) {
            $source->forceFill(['last_synced_at' => now()])->save();

            return $this->result(self::STATUS_OK, 'Sheet returned no data rows');
        }

        return $this->persistRows($source, $rows);
    }

    /**
     * Fetch the configured range and drop fully blank rows.
     *
     * @return list<array<int, string|null>>
     */
    public function fetchRows(SheetSource $source): array
    {
        $client = new GoogleClient;
        $client->setApplicationName((string) config('app.name', 'Omnitrack'));
        $client->setAuthConfig($this->credentialsPath());
        $client->addScope(Sheets::SPREADSHEETS_READONLY);
        $client->setAccessType('offline');

        $service = new Sheets($client);

        $response = $service->spreadsheets_values->get(
            $source->spreadsheet_id,
            $source->sheet_range
        );

        $values = $response->getValues() ?? [];

        return array_values(array_filter(
            $values,
            fn (array $row) => collect($row)->contains(fn ($cell) => filled($cell))
        ));
    }

    /**
     * Upsert fetched rows keyed on ['sheet_source_id', 'sheet_row_index'].
     *
     * The sheet range starts at A2, so the spreadsheet row number is index + 2.
     *
     * @param  list<array<int, string|null>>  $rows
     */
    public function persistRows(SheetSource $source, array $rows): array
    {
        $payload = [];
        $hashIndex = [];
        $skipped = 0;

        foreach ($rows as $offset => $row) {
            $sheetRow = $offset + 2;
            $name = trim((string) ($row[0] ?? ''));

            // A project without a name cannot be represented or matched.
            if ($name === '') {
                $skipped++;

                continue;
            }

            // Hash the raw row content as delivered (fixed column positions) so
            // a reordering of columns still registers as a content mutation.
            $rowHash = hash('sha256', json_encode(array_values($row), JSON_UNESCAPED_UNICODE));

            $hashIndex[$sheetRow] = $rowHash;

            $payload[] = [
                'sheet_source_id' => $source->id,
                'sheet_row_index' => $sheetRow,
                'name' => $name,
                'my_role' => $this->nullable($row[1] ?? null),
                'department' => $this->nullable($row[2] ?? null),
                'target_user' => $this->nullable($row[3] ?? null),
                'project_owner' => $this->nullable($row[4] ?? null),
                'description' => $this->nullable($row[5] ?? null),
                // Column 6 may be Indonesian ("Selesai") or English ("Done").
                'status' => ItemStatus::normalize($row[6] ?? null)->value,
                'doc_link' => $this->nullable($row[7] ?? null),
                'row_hash' => $rowHash,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($payload === []) {
            $source->forceFill(['last_synced_at' => now()])->save();

            return $this->result(self::STATUS_OK, 'No importable rows found', rows: count($rows), skipped: $skipped);
        }

        // Snapshot existing hashes to distinguish inserts from real mutations.
        $existing = Project::query()
            ->where('sheet_source_id', $source->id)
            ->whereIn('sheet_row_index', array_keys($hashIndex))
            ->pluck('row_hash', 'sheet_row_index');

        $created = 0;
        $updated = 0;

        foreach ($hashIndex as $sheetRow => $hash) {
            if (! $existing->has($sheetRow)) {
                $created++;
            } elseif ($existing[$sheetRow] !== $hash) {
                $updated++;
            }
        }

        // Reference key is the composite unique index on the table.
        Project::upsert(
            $payload,
            ['sheet_source_id', 'sheet_row_index'],
            [
                'name', 'my_role', 'department', 'target_user', 'project_owner',
                'description', 'status', 'doc_link', 'row_hash', 'updated_at',
            ]
        );

        $source->forceFill(['last_synced_at' => now()])->save();

        Log::info('Google Sheet ingestion complete.', [
            'sheet_source_id' => $source->id,
            'rows' => count($rows),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);

        return $this->result(
            self::STATUS_OK,
            sprintf('Synced %d row(s).', count($payload)),
            created: $created,
            updated: $updated,
            skipped: $skipped,
            rows: count($rows),
        );
    }

    private function nullable(mixed $value): ?string
    {
        $clean = trim((string) $value);

        return $clean === '' ? null : $clean;
    }

    /**
     * @return array{status: string, message: string, created: int, updated: int, skipped: int, rows: int}
     */
    private function result(
        string $status,
        string $message,
        int $created = 0,
        int $updated = 0,
        int $skipped = 0,
        int $rows = 0,
    ): array {
        return compact('status', 'message', 'created', 'updated', 'skipped', 'rows');
    }
}
