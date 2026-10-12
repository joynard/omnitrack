<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectModule;
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

    public function test_a_sheet_row_adopts_a_matching_manual_project(): void
    {
        $source = SheetSource::factory()->create();

        // The same work exists locally with modules attached, and the sheet spells the
        // name with extra spaces and a line break.
        $manual = Project::factory()->create(['name' => 'Penjualan Barang Claim']);
        ProjectModule::factory()->create(['project_id' => $manual->id, 'title' => 'Fullstack', 'status' => 'pending']);

        $result = app(GoogleSheetService::class)->persistRows($source, [
            ["Penjualan  Barang\nClaim", 'Dev', 'PT SPIL', 'User', 'Owner', 'Improve legacy', 'WIP', 'https://example.test/x'],
        ]);

        $this->assertSame(0, $result['created'], 'adoption must not create a second project');
        $this->assertSame(1, $result['linked']);

        $fresh = $manual->fresh();

        $this->assertSame($manual->id, $fresh->id);
        $this->assertSame($source->id, $fresh->sheet_source_id);
        $this->assertSame(2, $fresh->sheet_row_index);
        $this->assertSame('Penjualan Barang Claim', $fresh->name, 'the sheet name is normalized');
        $this->assertSame('Dev', $fresh->my_role);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_modules', 1);
    }

    public function test_a_repeated_sheet_name_is_only_adopted_once(): void
    {
        $source = SheetSource::factory()->create();
        Project::factory()->create(['name' => 'WLA']);

        $result = app(GoogleSheetService::class)->persistRows($source, [
            ['WLA', '', '', '', '', 'first', 'pending', ''],
            ['WLA', '', '', '', '', 'second', 'pending', ''],
        ]);

        $this->assertSame(1, $result['linked']);
        $this->assertSame(1, $result['created'], 'the second row has no project left to adopt');
        $this->assertDatabaseCount('projects', 2);
    }

    public function test_cells_collapse_newlines_and_links_lose_whitespace(): void
    {
        $source = SheetSource::factory()->create();

        app(GoogleSheetService::class)->persistRows($source, [
            ["Percepatan Sandar\r\nKapal di Kuala\nTanjung", '', '', '', '', "Current:\r* Rekap order CA", 'pending', "https://example.test/a\n b"],
        ]);

        $project = Project::query()->firstOrFail();

        $this->assertSame('Percepatan Sandar Kapal di Kuala Tanjung', $project->name);
        $this->assertSame('Current: * Rekap order CA', $project->description);
        $this->assertSame('https://example.test/ab', $project->doc_link);
    }
}
