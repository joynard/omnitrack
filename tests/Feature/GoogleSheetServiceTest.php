<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SheetSource;
use App\Services\GoogleSheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GoogleSheetServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_is_skipped_and_logged_when_credentials_are_missing(): void
    {
        // Point at a path that definitely does not exist.
        config()->set('services.google.credentials_path', storage_path('app/google/definitely-missing.json'));

        Log::shouldReceive('warning')
            ->once()
            ->with('Google credentials file missing. Ingestion skipped.', \Mockery::type('array'));

        $result = app(GoogleSheetService::class)->sync();

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('Credentials not injected yet', $result['message']);
        $this->assertSame(0, $result['created']);
    }

    public function test_credentials_present_reflects_file_existence(): void
    {
        $service = app(GoogleSheetService::class);

        config()->set('services.google.credentials_path', storage_path('app/google/definitely-missing.json'));
        $this->assertFalse($service->credentialsPresent());

        $path = storage_path('app/google/test-credentials.json');
        file_put_contents($path, json_encode(['type' => 'service_account']));
        config()->set('services.google.credentials_path', $path);

        try {
            $this->assertTrue($service->credentialsPresent());
        } finally {
            @unlink($path);
        }
    }

    public function test_persist_rows_upserts_on_source_and_row_index(): void
    {
        $source = SheetSource::factory()->create();
        $service = app(GoogleSheetService::class);

        $rows = [
            ['Alpha Project', 'Lead', 'Ops', 'Budi', 'Siti', 'Desc A', 'WIP', 'https://example.test/a'],
            ['Beta Project', 'Support', 'Finance', 'Ani', 'Rudi', 'Desc B', 'Selesai 100%', null],
        ];

        $first = $service->persistRows($source, $rows);

        $this->assertSame('ok', $first['status']);
        $this->assertSame(2, $first['created']);
        $this->assertSame(0, $first['updated']);
        $this->assertDatabaseCount('projects', 2);
        $this->assertDatabaseHas('projects', [
            'name' => 'Alpha Project',
            'status' => 'in_progress',
            'sheet_row_index' => 2,
        ]);
        $this->assertDatabaseHas('projects', [
            'name' => 'Beta Project',
            'status' => 'done',
            'sheet_row_index' => 3,
        ]);

        // Re-running with identical content must not create duplicates.
        $second = $service->persistRows($source, $rows);

        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['updated']);
        $this->assertDatabaseCount('projects', 2);

        // Mutating one row must be detected as an update, not an insert.
        $rows[0][6] = 'Selesai';
        $third = $service->persistRows($source, $rows);

        $this->assertSame(0, $third['created']);
        $this->assertSame(1, $third['updated']);
        $this->assertDatabaseCount('projects', 2);
        $this->assertDatabaseHas('projects', ['name' => 'Alpha Project', 'status' => 'done']);
    }

    public function test_rows_without_a_name_are_skipped(): void
    {
        $source = SheetSource::factory()->create();
        $service = app(GoogleSheetService::class);

        $result = $service->persistRows($source, [
            ['', 'Lead', '', '', '', '', '', ''],
            ['   ', '', '', '', '', '', '', ''],
            ['Valid Project', '', '', '', '', '', 'pending', ''],
        ]);

        $this->assertSame(3, $result['rows']);
        $this->assertSame(2, $result['skipped']);
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_upsert_never_touches_manual_projects(): void
    {
        $source = SheetSource::factory()->create();
        $manual = Project::factory()->create(['name' => 'Manual Project']);

        app(GoogleSheetService::class)->persistRows($source, [
            ['Sheet Project', '', '', '', '', '', 'pending', ''],
        ]);

        // The manual row has a null sheet_source_id, so it must be untouched.
        $this->assertDatabaseHas('projects', ['id' => $manual->id, 'name' => 'Manual Project']);
        $this->assertDatabaseCount('projects', 2);
    }
}
