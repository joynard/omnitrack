<?php

use App\Jobs\SyncGoogleSheetJob;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes / Schedule
|--------------------------------------------------------------------------
| The PRD calls for 24/7 unattended operation, so the sheet ingestion runs
| on a schedule. On Koyeb a single container runs the scheduler via the
| queue worker container's `schedule:work`, or add it as a fourth supervisor
| program if you prefer a dedicated process.
*/

Schedule::job(new SyncGoogleSheetJob)
    ->hourly()
    ->withoutOverlapping()
    ->name('sync-google-sheet');
