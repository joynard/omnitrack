<?php

namespace App\Jobs;

use App\Services\GoogleSheetService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Queued Google Sheet ingestion.
 *
 * The job never throws for the "credentials missing" case: that is an expected
 * state on a fresh deploy, and failing the job would fill `failed_jobs` and
 * spam retries for a condition the user simply has not configured yet.
 */
class SyncGoogleSheetJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public ?string $sheetSourceId = null) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(GoogleSheetService $sheets): void
    {
        $source = $this->sheetSourceId !== null
            ? \App\Models\SheetSource::find($this->sheetSourceId)
            : null;

        $result = $sheets->sync($source);

        match ($result['status']) {
            GoogleSheetService::STATUS_FAILED => Log::error('Google Sheet sync job failed.', $result),
            GoogleSheetService::STATUS_SKIPPED => Log::info('Google Sheet sync job skipped.', $result),
            default => Log::info('Google Sheet sync job finished.', $result),
        };
    }
}
