<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Task;
use App\Services\AiOrchestrator;
use App\Services\WorkspaceTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

/**
 * Bulk targeting for the agent tool surface.
 *
 * The contract under test is the selection rule shared by every mutating tool:
 * an explicit id list wins, then `all: true` narrowed by `where`, then a single
 * id -- and an `all` that names nothing is refused unless `confirm: true`.
 *
 * NOTE: TaskFactory and ProjectModuleFactory do not define `title`, and the
 * column is NOT NULL, so every fixture below passes it explicitly. The same gap
 * is the root cause of the pre-existing WorkspaceControlTest failures.
 */
class BulkWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $arguments */
    private function tool(string $name, array $arguments): array
    {
        return app(AiOrchestrator::class)->callTool($name, $arguments);
    }

    /**
     * @return array{0: Project, 1: Collection<int, ProjectModule>}
     */
    private function projectWithModules(int $count, string $name = 'Proyek Uji'): array
    {
        $project = Project::factory()->create(['name' => $name]);

        for ($i = 0; $i < $count; $i++) {
            ProjectModule::factory()->create([
                'project_id' => $project->id,
                'title' => $name.' modul '.($i + 1),
                'order_index' => $i,
            ]);
        }

        return [$project, $project->modules()->get()];
    }

    // --------------------------------------------------------------- modules

    public function test_modules_are_deleted_from_an_explicit_id_list(): void
    {
        [, $modules] = $this->projectWithModules(3);

        $result = $this->tool('delete_module', [
            'module_ids' => [$modules[0]->id, $modules[1]->id],
        ]);

        $this->assertDatabaseCount('project_modules', 1);
        $this->assertDatabaseHas('project_modules', ['id' => $modules[2]->id]);
        $this->assertStringContainsString('2 modul dihapus', $result['message']);
    }

    public function test_every_module_of_one_project_is_deleted_without_confirm(): void
    {
        [$project] = $this->projectWithModules(3, 'Alpha');
        [$other] = $this->projectWithModules(2, 'Beta');

        // A scoped `all` names its project, so it is not the dangerous case and
        // deliberately does not demand `confirm`.
        $this->tool('delete_module', [
            'all' => true,
            'where' => ['project_id' => $project->id],
        ]);

        $this->assertSame(0, ProjectModule::where('project_id', $project->id)->count());
        $this->assertSame(2, ProjectModule::where('project_id', $other->id)->count());
    }

    public function test_unscoped_all_is_refused_without_confirm(): void
    {
        $this->projectWithModules(2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SELURUH modul');

        $this->tool('delete_module', ['all' => true]);
    }

    public function test_unscoped_all_deletes_everything_with_confirm(): void
    {
        $this->projectWithModules(2, 'Alpha');
        $this->projectWithModules(3, 'Beta');

        $this->tool('delete_module', ['all' => true, 'confirm' => true]);

        $this->assertDatabaseCount('project_modules', 0);
    }

    public function test_naming_no_target_at_all_is_refused(): void
    {
        $this->projectWithModules(1);

        $this->expectException(RuntimeException::class);

        $this->tool('delete_module', []);
    }

    public function test_an_unsupported_where_key_is_rejected_rather_than_ignored(): void
    {
        $this->projectWithModules(2);

        // Projects carry no `priority` column. Silently dropping the filter would
        // delete the whole scope while the caller believed it was narrowed.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak dikenal');

        $this->tool('delete_project', [
            'all' => true,
            'where' => ['priority' => 'high'],
        ]);
    }

    public function test_a_blank_where_value_does_not_count_as_a_scope(): void
    {
        $this->projectWithModules(2);

        // {"status": null} narrows nothing, so it must still demand confirm --
        // otherwise it reads as scoped while behaving as a full wipe.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SELURUH modul');

        $this->tool('delete_module', ['all' => true, 'where' => ['status' => null]]);
    }

    public function test_tasks_are_filtered_by_status_before_being_deleted(): void
    {
        Task::factory()->create(['title' => 'Selesai', 'status' => 'done', 'order_index' => 0]);
        Task::factory()->create(['title' => 'Masih', 'status' => 'pending', 'order_index' => 1]);

        $this->tool('delete_task', [
            'all' => true,
            'where' => ['status' => 'done'],
        ]);

        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseHas('tasks', ['title' => 'Masih']);
    }

    public function test_modules_are_bulk_updated_through_a_where_filter(): void
    {
        [$project, $modules] = $this->projectWithModules(3);

        // ProjectModuleFactory does not pin a status, so state the fixture
        // explicitly: the filter below must match exactly two rows, whatever the
        // factory happened to generate.
        ProjectModule::whereIn('id', $modules->modelKeys())->update(['status' => 'pending']);
        ProjectModule::whereKey($modules[0]->id)->update(['status' => 'done']);

        $result = $this->tool('update_module', [
            'all' => true,
            'where' => ['project_id' => $project->id, 'status' => 'pending'],
            'status' => 'done',
        ]);

        $this->assertSame(3, ProjectModule::where('status', 'done')->count());
        $this->assertStringContainsString('2 modul diperbarui', $result['message']);
    }

    public function test_where_status_accepts_indonesian_values(): void
    {
        [$project, $modules] = $this->projectWithModules(2);
        ProjectModule::whereIn('id', $modules->modelKeys())->update(['status' => 'pending']);
        ProjectModule::whereKey($modules[0]->id)->update(['status' => 'done']);

        // "selesai" is normalised by ItemStatus before it reaches the query, and
        // only the done row may be selected -- otherwise this test would pass
        // even with the status filter ignored altogether.
        $this->tool('delete_module', [
            'all' => true,
            'where' => ['project_id' => $project->id, 'status' => 'selesai'],
        ]);

        $this->assertDatabaseCount('project_modules', 1);
        $this->assertDatabaseHas('project_modules', ['id' => $modules[1]->id]);
    }

    public function test_one_module_still_updates_through_the_single_id_path(): void
    {
        [, $modules] = $this->projectWithModules(1);

        $result = $this->tool('update_module', [
            'module_id' => $modules[0]->id,
            'status' => 'done',
        ]);

        $this->assertSame('done', $modules[0]->fresh()->status->value);
        // The single-row response shape is unchanged from before bulk support.
        $this->assertArrayHasKey('module', $result['data']);
    }

    // -------------------------------------------------------------- projects

    public function test_projects_are_deleted_in_bulk_with_their_modules(): void
    {
        $a = Project::factory()->create(['name' => 'A']);
        ProjectModule::factory()->count(2)->create(['project_id' => $a->id, 'title' => 'M']);
        $b = Project::factory()->create(['name' => 'B']);
        $kept = Project::factory()->create(['name' => 'C']);

        $result = $this->tool('delete_project', ['project_ids' => [$a->id, $b->id]]);

        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseHas('projects', ['id' => $kept->id]);
        $this->assertDatabaseCount('project_modules', 0);
        $this->assertStringContainsString('2 project dihapus', $result['message']);
    }

    public function test_projects_are_bulk_updated_within_a_category_scope(): void
    {
        $source = Category::factory()->create(['name' => 'Kantor']);
        $target = Category::factory()->create(['name' => 'Arsip']);

        foreach (['P1', 'P2'] as $name) {
            Project::factory()->create(['name' => $name, 'category_id' => $source->id]);
        }

        $untouched = Project::factory()->create(['name' => 'P3']);

        $this->tool('update_project', [
            'all' => true,
            'where' => ['category_id' => $source->id],
            'category_id' => $target->id,
        ]);

        $this->assertSame(2, Project::where('category_id', $target->id)->count());
        $this->assertNull($untouched->fresh()->category_id);
    }

    public function test_reorder_projects_exists_and_applies_the_new_order(): void
    {
        $a = Project::factory()->create(['name' => 'A', 'order_index' => 0]);
        $b = Project::factory()->create(['name' => 'B', 'order_index' => 1]);

        $result = $this->tool('reorder_projects', ['project_ids' => [$b->id, $a->id]]);

        $this->assertSame(0, $b->fresh()->order_index);
        $this->assertSame(1, $a->fresh()->order_index);
        $this->assertSame(2, $result['data']['reordered']);
    }

    // ----------------------------------------------------------------- tasks

    public function test_tasks_are_bulk_updated_then_bulk_deleted(): void
    {
        foreach (['T1', 'T2', 'T3'] as $index => $title) {
            Task::factory()->create(['title' => $title, 'order_index' => $index]);
        }

        $this->tool('update_task', [
            'all' => true,
            'where' => ['status' => 'pending'],
            'status' => 'done',
        ]);

        $this->assertSame(3, Task::where('status', 'done')->count());

        $this->tool('delete_task', [
            'all' => true,
            'where' => ['status' => 'done'],
        ]);

        $this->assertDatabaseCount('tasks', 0);
    }

    // ------------------------------------------------------------ categories

    public function test_categories_are_deleted_in_bulk_and_projects_survive(): void
    {
        $a = Category::factory()->create(['name' => 'A']);
        $b = Category::factory()->create(['name' => 'B']);
        $project = Project::factory()->create(['name' => 'P', 'category_id' => $a->id]);

        $this->tool('delete_category', ['category_ids' => [$a->id, $b->id]]);

        $this->assertDatabaseCount('categories', 0);
        $this->assertNull($project->fresh()->category_id);
    }

    public function test_delete_category_accepts_a_plain_name(): void
    {
        // The tool schema has always advertised `name` as an alternative to
        // category_id, but the resolver only ever read `category_name`, so this
        // path threw "Kategori tidak ditemukan" before the split resolver.
        $category = Category::factory()->create(['name' => 'Pribadi']);

        $this->tool('delete_category', ['name' => 'Pribadi']);

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_delete_category_no_longer_creates_a_missing_category(): void
    {
        $this->expectException(RuntimeException::class);

        // resolveCategoryId()'s firstOrCreate used to make this "create it, then
        // delete it" -- a silent insert on a delete path.
        $this->tool('delete_category', ['name' => 'Tidak Ada']);

        $this->assertDatabaseCount('categories', 0);
    }

    // ------------------------------------------------------------ HTTP layer

    public function test_bulk_endpoint_deletes_several_modules(): void
    {
        [, $modules] = $this->projectWithModules(3);

        $this->withToken('test-harness-token')
            ->postJson('/api/harness/modules/bulk', [
                'action' => 'delete',
                'module_ids' => [$modules[0]->id, $modules[2]->id],
            ])
            ->assertOk();

        $this->assertDatabaseCount('project_modules', 1);
    }

    public function test_bulk_endpoint_refuses_unscoped_all_without_confirm(): void
    {
        $this->projectWithModules(2);

        $this->withToken('test-harness-token')
            ->postJson('/api/harness/modules/bulk', ['action' => 'delete', 'all' => true])
            ->assertStatus(422);

        $this->assertDatabaseCount('project_modules', 2);
    }

    public function test_bulk_endpoint_updates_tasks_by_scope(): void
    {
        Task::factory()->create(['title' => 'T1', 'order_index' => 0]);

        $this->withToken('test-harness-token')
            ->postJson('/api/harness/tasks/bulk', [
                'action' => 'update',
                'all' => true,
                'where' => ['status' => 'pending'],
                'status' => 'done',
            ])
            ->assertOk();

        $this->assertDatabaseHas('tasks', ['title' => 'T1', 'status' => 'done']);
    }

    public function test_projects_reorder_endpoint_returns_through_the_tool_layer(): void
    {
        $a = Project::factory()->create(['name' => 'A', 'order_index' => 0]);
        $b = Project::factory()->create(['name' => 'B', 'order_index' => 1]);

        $this->withToken('test-harness-token')
            ->postJson('/api/harness/projects/reorder', ['project_ids' => [$b->id, $a->id]])
            ->assertOk()
            ->assertJsonPath('data.reordered', 2);
    }

    public function test_tool_catalogue_advertises_the_bulk_selector(): void
    {
        $tools = collect(WorkspaceTools::all())
            ->keyBy(fn (array $tool) => $tool['function']['name']);

        $this->assertContains('reorder_projects', $tools->keys()->all());

        $properties = $tools['delete_module']['function']['parameters']['properties'];

        foreach (['module_ids', 'all', 'where', 'confirm', 'module_id'] as $key) {
            $this->assertArrayHasKey($key, $properties);
        }

        // Nothing may be hard-required any more: a bulk call names no single id.
        foreach (['delete_module', 'update_module', 'delete_task', 'update_task', 'delete_project'] as $name) {
            $this->assertSame([], $tools[$name]['function']['parameters']['required']);
        }
    }

    // ---------------------------------------------------- categories, part two

    public function test_categories_can_be_reordered(): void
    {
        $a = Category::factory()->create(['name' => 'A', 'order_index' => 0]);
        $b = Category::factory()->create(['name' => 'B', 'order_index' => 1]);

        $result = $this->tool('reorder_categories', ['category_ids' => [$b->id, $a->id]]);

        $this->assertSame(0, $b->fresh()->order_index);
        $this->assertSame(1, $a->fresh()->order_index);
        $this->assertSame(2, $result['data']['reordered']);
    }

    public function test_create_category_stores_a_colour_and_an_explicit_order(): void
    {
        $result = $this->tool('create_category', [
            'name' => 'Kuliah',
            'color' => '#7C3AED',
            'order_index' => 4,
        ]);

        $this->assertSame('#7C3AED', $result['data']['category']['color']);
        $this->assertSame(4, $result['data']['category']['order_index']);
    }

    public function test_create_category_updates_the_colour_of_an_existing_row(): void
    {
        // Built through the tool, not the factory: CategoryFactory sets a faker
        // `slug` that is not derived from `name`, so a factory row is not
        // findable by the slug this tool looks up.
        $created = $this->tool('create_category', ['name' => 'Kantor'])['data']['category'];

        $this->assertNotSame('#0EA5E9', $created['color']);

        // The endpoint accepted `color` and the tool dropped it, so a caller had
        // no way to tell that nothing happened. A category always HAS a colour --
        // the column is NOT NULL and defaults to "neutral" -- so this is an
        // update rather than a fill.
        $this->tool('create_category', ['name' => 'Kantor', 'color' => '#0EA5E9']);

        $this->assertSame('#0EA5E9', Category::whereKey($created['id'])->value('color'));
        $this->assertDatabaseCount('categories', 1);
    }

    // ------------------------------------------------------ modules, part two

    public function test_modules_can_be_moved_to_another_project(): void
    {
        [$source, $modules] = $this->projectWithModules(2, 'Sumber');
        $destination = Project::factory()->create(['name' => 'Tujuan']);

        ProjectModule::factory()->create([
            'project_id' => $destination->id,
            'title' => 'Sudah ada',
            'order_index' => 7,
        ]);

        $this->tool('update_module', [
            'module_ids' => $modules->modelKeys(),
            'move_to_project_id' => $destination->id,
        ]);

        $this->assertSame(0, ProjectModule::where('project_id', $source->id)->count());
        $this->assertSame(3, ProjectModule::where('project_id', $destination->id)->count());

        // Appended after the destination's current maximum, keeping the order the
        // caller listed them in.
        $this->assertSame(8, $modules[0]->fresh()->order_index);
        $this->assertSame(9, $modules[1]->fresh()->order_index);
    }

    public function test_moving_to_an_unknown_project_is_refused(): void
    {
        [, $modules] = $this->projectWithModules(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Project tujuan tidak ditemukan');

        $this->tool('update_module', [
            'module_id' => $modules[0]->id,
            'move_to_project_id' => 'not-a-project',
        ]);
    }

    // ------------------------------------------------------------- due dates

    public function test_module_due_dates_are_shifted_per_row(): void
    {
        [$project, $modules] = $this->projectWithModules(2);
        ProjectModule::whereKey($modules[0]->id)->update(['due_date' => '2026-03-01']);
        ProjectModule::whereKey($modules[1]->id)->update(['due_date' => '2026-03-10']);

        $result = $this->tool('update_module', [
            'all' => true,
            'where' => ['project_id' => $project->id],
            'due_date_shift_days' => 7,
        ]);

        $this->assertSame('2026-03-08', $modules[0]->fresh()->due_date->toDateString());
        $this->assertSame('2026-03-17', $modules[1]->fresh()->due_date->toDateString());
        $this->assertStringContainsString('digeser 7 hari', $result['message']);
    }

    public function test_shifting_leaves_rows_without_a_due_date_alone(): void
    {
        [, $modules] = $this->projectWithModules(2);
        ProjectModule::whereKey($modules[0]->id)->update(['due_date' => '2026-03-01']);

        $this->tool('update_module', [
            'module_ids' => $modules->modelKeys(),
            'due_date_shift_days' => 1,
        ]);

        $this->assertSame('2026-03-02', $modules[0]->fresh()->due_date->toDateString());
        $this->assertNull($modules[1]->fresh()->due_date);
    }

    public function test_task_due_dates_are_shifted_per_row(): void
    {
        $task = Task::factory()->create([
            'title' => 'T',
            'due_date' => '2026-03-01',
            'order_index' => 0,
        ]);

        $this->tool('update_task', ['task_id' => $task->id, 'due_date_shift_days' => -2]);

        $this->assertSame('2026-02-27', $task->fresh()->due_date->toDateString());
    }

    public function test_due_date_and_shift_together_are_refused(): void
    {
        [, $modules] = $this->projectWithModules(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bukan keduanya');

        $this->tool('update_module', [
            'module_id' => $modules[0]->id,
            'due_date' => '2026-04-01',
            'due_date_shift_days' => 3,
        ]);
    }

    public function test_due_date_accepts_a_relative_expression(): void
    {
        [, $modules] = $this->projectWithModules(1);

        $this->tool('update_module', [
            'module_id' => $modules[0]->id,
            'due_date' => '+7 days',
        ]);

        $this->assertSame(
            now()->addDays(7)->toDateString(),
            $modules[0]->fresh()->due_date->toDateString(),
        );
    }

    // --------------------------------------------------------- schema honesty

    public function test_where_advertises_only_the_filters_an_entity_supports(): void
    {
        $tools = collect(WorkspaceTools::all())
            ->keyBy(fn (array $tool) => $tool['function']['name']);

        $whereOf = fn (string $name) => $tools[$name]['function']['parameters']['properties']['where']['properties'];

        $this->assertSame(['status', 'category_id'], array_keys($whereOf('delete_project')));
        $this->assertSame(['status', 'priority', 'project_id'], array_keys($whereOf('delete_module')));
        $this->assertSame(['status', 'priority'], array_keys($whereOf('delete_task')));

        // Categories have neither column, so `all: true` there is always a
        // whole-workspace wipe and always needs confirm -- which the empty filter
        // set makes impossible to mistake for a scope.
        $this->assertSame([], (array) $whereOf('delete_category'));

        $this->assertCount(16, $tools->all());

        foreach (['reorder_categories', 'reorder_projects', 'reorder_modules', 'reorder_tasks'] as $name) {
            $this->assertArrayHasKey($name, $tools->all());
        }
    }
}
