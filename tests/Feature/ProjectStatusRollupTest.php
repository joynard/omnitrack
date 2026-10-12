<?php

namespace Tests\Feature;

use App\Enums\ItemStatus;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A project's status follows its checklist.
 *
 * Ticking every module has to mean the project is done, otherwise the board shows
 * a finished project as in progress forever. These tests pin the rollup rule and,
 * just as importantly, the three paths that do not go through a model event:
 * mass update, mass delete, and a module that moves to another project.
 */
class ProjectStatusRollupTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function project(string $status = 'pending'): Project
    {
        return Project::factory()->create([
            'name' => 'Rollup '.++$this->sequence,
            'status' => $status,
        ]);
    }

    private function module(Project $project, string $status): ProjectModule
    {
        return ProjectModule::factory()->create([
            'project_id' => $project->id,
            'title' => 'Modul '.++$this->sequence,
            'status' => $status,
        ]);
    }

    public function test_all_modules_done_marks_the_project_done(): void
    {
        $project = $this->project('in_progress');

        $this->module($project, 'done');
        $open = $this->module($project, 'pending');

        $this->assertSame(ItemStatus::IN_PROGRESS, $project->fresh()->status, 'One open module means unfinished.');

        $open->update(['status' => 'done']);

        $this->assertSame(ItemStatus::DONE, $project->fresh()->status);
    }

    public function test_one_open_module_demotes_a_finished_project(): void
    {
        $project = $this->project('done');

        $this->module($project, 'done');
        $this->module($project, 'pending');

        $this->assertSame(ItemStatus::IN_PROGRESS, $project->fresh()->status);
    }

    public function test_pending_and_in_progress_are_left_to_the_user(): void
    {
        // An untouched checklist must not drag an in_progress project back to
        // pending: "we started, the checklist is not written yet" is a real state.
        $project = $this->project('in_progress');
        $this->module($project, 'pending');

        $this->assertSame(ItemStatus::IN_PROGRESS, $project->fresh()->status);

        $untouched = $this->project('pending');
        $this->module($untouched, 'pending');

        $this->assertSame(ItemStatus::PENDING, $untouched->fresh()->status);
    }

    public function test_adding_a_module_to_a_done_project_reopens_it(): void
    {
        $project = $this->project('in_progress');
        $this->module($project, 'done');

        $this->assertSame(ItemStatus::DONE, $project->fresh()->status);

        $this->module($project, 'pending');

        $this->assertSame(ItemStatus::IN_PROGRESS, $project->fresh()->status);
    }

    public function test_deleting_the_last_open_module_completes_the_project(): void
    {
        $project = $this->project('in_progress');
        $this->module($project, 'done');
        $open = $this->module($project, 'pending');

        $this->assertSame(ItemStatus::IN_PROGRESS, $project->fresh()->status);

        $open->delete();

        $this->assertSame(ItemStatus::DONE, $project->fresh()->status);
    }

    public function test_an_empty_project_is_never_rolled_up(): void
    {
        // No modules means no opinion: the status stays what it was created with.
        $project = $this->project('in_progress');
        $this->assertSame(ItemStatus::IN_PROGRESS, $project->fresh()->status);

        // And removing the last module leaves the status it already had, rather
        // than resetting a finished project to pending.
        $module = $this->module($project, 'done');
        $this->assertSame(ItemStatus::DONE, $project->fresh()->status);

        $module->delete();

        $this->assertSame(ItemStatus::DONE, $project->fresh()->status);
    }

    public function test_board_module_endpoint_returns_the_rolled_up_project(): void
    {
        $project = $this->project('in_progress');
        $module = $this->module($project, 'pending');

        $this->actingAs(User::factory()->create())
            ->postJson('/board/modules/'.$module->id, ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('project.status', 'done')
            ->assertJsonPath('project.status_label', 'Done')
            ->assertJsonPath('project.modules_done', 1)
            ->assertJsonPath('project.modules_count', 1)
            ->assertJsonPath('project.progress', 100);

        $this->assertSame(ItemStatus::DONE, $project->fresh()->status);
    }

    public function test_board_module_delete_returns_the_rolled_up_project(): void
    {
        $project = $this->project('in_progress');
        $this->module($project, 'done');
        $open = $this->module($project, 'pending');

        $this->actingAs(User::factory()->create())
            ->deleteJson('/board/modules/'.$open->id)
            ->assertOk()
            ->assertJsonPath('project.status', 'done');

        $this->assertSame(ItemStatus::DONE, $project->fresh()->status);
    }

    public function test_single_module_update_reports_the_project_status(): void
    {
        $project = $this->project('in_progress');
        $module = $this->module($project, 'pending');

        $this->withToken('test-harness-token')
            ->postJson('/api/harness/modules/'.$module->id, ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.project_status', 'done');
    }

    public function test_mass_update_rolls_the_project_up(): void
    {
        $project = $this->project('pending');
        $this->module($project, 'done');
        $this->module($project, 'pending');

        // No destination and no date shift, so this takes the mass-update branch,
        // which fires no model events.
        $this->withToken('test-harness-token')
            ->postJson('/api/harness/modules/bulk', [
                'action' => 'update',
                'all' => true,
                'where' => ['status' => 'pending'],
                'status' => 'done',
            ])
            ->assertOk();

        $this->assertSame(ItemStatus::DONE, $project->fresh()->status);
    }

    public function test_mass_delete_rolls_the_project_up(): void
    {
        $project = $this->project('in_progress');
        $this->module($project, 'done');
        $this->module($project, 'pending');

        $this->withToken('test-harness-token')
            ->postJson('/api/harness/modules/bulk', [
                'action' => 'delete',
                'all' => true,
                'where' => ['status' => 'pending'],
                'confirm' => true,
            ])
            ->assertOk();

        $this->assertSame(ItemStatus::DONE, $project->fresh()->status);
    }

    public function test_moving_a_module_rolls_up_both_projects(): void
    {
        $from = $this->project('in_progress');
        $to = $this->project('in_progress');

        $this->module($from, 'done');
        $open = $this->module($from, 'pending');
        $this->module($to, 'done');
        $this->module($to, 'pending');

        $this->withToken('test-harness-token')
            ->postJson('/api/harness/modules/bulk', [
                'action' => 'update',
                'module_ids' => [$open->id],
                'move_to_project_id' => $to->id,
            ])
            ->assertOk();

        // The source lost its only open module, the destination still has one.
        $this->assertSame(ItemStatus::DONE, $from->fresh()->status);
        $this->assertSame(ItemStatus::IN_PROGRESS, $to->fresh()->status);
    }
}
