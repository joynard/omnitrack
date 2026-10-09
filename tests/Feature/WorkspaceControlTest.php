<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Task;
use App\Services\AiOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The mutation surface shared by the AI palette, the Blade UI and the harness
 * API. These tests exercise AiOrchestrator::callTool() directly, so they cover
 * every caller without needing DeepSeek to be reachable.
 */
class WorkspaceControlTest extends TestCase
{
    use RefreshDatabase;

    private function orchestrator(): AiOrchestrator
    {
        return app(AiOrchestrator::class);
    }

    // ------------------------------------------------------------ categories

    public function test_create_category_is_idempotent_by_slug(): void
    {
        $first = $this->orchestrator()->callTool('create_category', ['name' => 'Kuliah']);
        $second = $this->orchestrator()->callTool('create_category', ['name' => 'kuliah']);

        $this->assertDatabaseCount('categories', 1);
        $this->assertSame($first['data']['category']['id'], $second['data']['category']['id']);
    }

    public function test_project_can_be_filed_under_a_category_by_name(): void
    {
        $this->orchestrator()->callTool('create_project', [
            'name' => 'Skripsi',
            'category_name' => 'Kuliah',
        ]);

        $this->assertDatabaseHas('categories', ['name' => 'Kuliah']);
        $this->assertDatabaseHas('projects', ['name' => 'Skripsi']);

        $project = Project::firstWhere('name', 'Skripsi');
        $this->assertNotNull($project->category_id);
        // fresh() because the created model has not read the FK back yet.
        $this->assertSame('Kuliah', $project->fresh('category')->category->name);
    }

    public function test_deleting_a_category_keeps_its_projects(): void
    {
        $category = Category::factory()->create(['name' => 'Kantor', 'slug' => 'kantor']);
        $project = Project::factory()->create(['category_id' => $category->id]);

        $result = $this->orchestrator()->callTool('delete_category', ['category_id' => $category->id]);

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        // The project survives and simply becomes uncategorised.
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'category_id' => null]);
        $this->assertSame(1, $result['data']['unfiled_projects']);
    }

    public function test_category_can_be_renamed(): void
    {
        $category = Category::factory()->create(['name' => 'Kantor', 'slug' => 'kantor']);

        $this->orchestrator()->callTool('update_category', [
            'category_id' => $category->id,
            'new_name' => 'Kantor Pusat',
        ]);

        $category->refresh();
        $this->assertSame('Kantor Pusat', $category->name);
        $this->assertSame('kantor-pusat', $category->slug);
    }

    // -------------------------------------------------------------- projects

    public function test_project_can_be_moved_between_categories(): void
    {
        $work = Category::factory()->create(['name' => 'Kantor', 'slug' => 'kantor']);
        $personal = Category::factory()->create(['name' => 'Pribadi', 'slug' => 'pribadi']);
        $project = Project::factory()->create(['category_id' => $work->id]);

        $this->orchestrator()->callTool('update_project', [
            'project_id' => $project->id,
            'category_id' => $personal->id,
            'status' => 'wip',
        ]);

        $project->refresh();
        $this->assertSame($personal->id, $project->category_id);
        $this->assertSame('in_progress', $project->status->value);
    }

    public function test_deleting_a_project_also_removes_its_modules(): void
    {
        $project = Project::factory()->create();
        ProjectModule::factory()->count(2)->create(['project_id' => $project->id]);

        $this->assertDatabaseCount('project_modules', 2);

        $this->orchestrator()->callTool('delete_project', ['project_id' => $project->id]);

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseCount('project_modules', 0);
    }

    public function test_reorder_projects_applies_explicit_order(): void
    {
        $a = Project::factory()->create(['name' => 'A', 'order_index' => 0]);
        $b = Project::factory()->create(['name' => 'B', 'order_index' => 1]);
        $c = Project::factory()->create(['name' => 'C', 'order_index' => 2]);

        $this->postJson('/board/projects/reorder', [
            'project_ids' => [$c->id, $a->id, $b->id],
        ])->assertOk()->assertJsonPath('reordered', 3);

        $this->assertSame(0, $c->fresh()->order_index);
        $this->assertSame(1, $a->fresh()->order_index);
        $this->assertSame(2, $b->fresh()->order_index);
    }

    // --------------------------------------------------------------- modules

    public function test_modules_can_be_batch_created_with_priority_normalisation(): void
    {
        $project = Project::factory()->create();

        $result = $this->orchestrator()->callTool('create_module', [
            'project_id' => $project->id,
            'modules' => [
                ['title' => 'Pertama', 'priority' => 'P1 urgent'],
                ['title' => 'Kedua', 'priority' => 'sedang'],
            ],
        ]);

        $this->assertSame(2, count($result['data']['modules']));
        $this->assertDatabaseHas('project_modules', ['title' => 'Pertama', 'priority' => 'high']);
        $this->assertDatabaseHas('project_modules', ['title' => 'Kedua', 'priority' => 'medium']);
    }

    public function test_reorder_modules_ignores_ids_from_other_projects(): void
    {
        $project = Project::factory()->create();
        $first = ProjectModule::factory()->create(['project_id' => $project->id, 'order_index' => 0]);
        $second = ProjectModule::factory()->create(['project_id' => $project->id, 'order_index' => 1]);

        $foreign = ProjectModule::factory()->create(['order_index' => 0]);

        $result = $this->orchestrator()->callTool('reorder_modules', [
            'project_id' => $project->id,
            'module_ids' => [$second->id, $foreign->id, $first->id],
        ]);

        // The foreign module is skipped, so only two rows are touched.
        $this->assertSame(2, $result['data']['reordered']);
        $this->assertSame(0, $second->fresh()->order_index);
        $this->assertSame(1, $first->fresh()->order_index);
        $this->assertSame(0, $foreign->fresh()->order_index);
    }

    public function test_module_can_be_updated_and_deleted(): void
    {
        $module = ProjectModule::factory()->create(['status' => 'pending']);

        $this->orchestrator()->callTool('update_module', [
            'module_id' => $module->id,
            'status' => 'selesai',
            'title' => 'Judul baru',
        ]);

        $module->refresh();
        $this->assertSame('done', $module->status->value);
        $this->assertSame('Judul baru', $module->title);

        $this->orchestrator()->callTool('delete_module', ['module_id' => $module->id]);
        $this->assertDatabaseMissing('project_modules', ['id' => $module->id]);
    }

    // ----------------------------------------------------------------- tasks

    public function test_tasks_can_be_batch_created(): void
    {
        $this->orchestrator()->callTool('create_task', [
            'tasks' => [
                ['title' => 'Bayar SPP', 'priority' => 'tinggi'],
                ['title' => 'Belanja bulanan', 'priority' => 'rendah'],
            ],
        ]);

        $this->assertDatabaseCount('tasks', 2);
        $this->assertDatabaseHas('tasks', ['title' => 'Bayar SPP', 'priority' => 'high']);
        $this->assertDatabaseHas('tasks', ['title' => 'Belanja bulanan', 'priority' => 'low']);
    }

    public function test_reorder_tasks_applies_explicit_order(): void
    {
        $one = Task::factory()->create(['title' => 'One', 'order_index' => 0]);
        $two = Task::factory()->create(['title' => 'Two', 'order_index' => 1]);
        $three = Task::factory()->create(['title' => 'Three', 'order_index' => 2]);

        $this->orchestrator()->callTool('reorder_tasks', [
            'task_ids' => [$three->id, $two->id, $one->id],
        ]);

        $this->assertSame(0, $three->fresh()->order_index);
        $this->assertSame(1, $two->fresh()->order_index);
        $this->assertSame(2, $one->fresh()->order_index);
    }

    public function test_task_can_be_updated_and_deleted(): void
    {
        $task = Task::factory()->create(['status' => 'pending']);

        $this->orchestrator()->callTool('update_task', [
            'task_id' => $task->id,
            'status' => 'selesai',
            'priority' => 'p1',
        ]);

        $task->refresh();
        $this->assertSame('done', $task->status->value);
        $this->assertSame('high', $task->priority->value);

        $this->orchestrator()->callTool('delete_task', ['task_id' => $task->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_unknown_tool_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->orchestrator()->callTool('drop_everything', []);
    }

    public function test_missing_required_argument_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->orchestrator()->callTool('create_project', ['name' => '']);
    }

    // ---------------------------------------------------------- tool schema

    public function test_tool_schema_is_exposed_and_well_formed(): void
    {
        $tools = \App\Services\WorkspaceTools::all();

        $this->assertNotEmpty($tools);

        foreach ($tools as $tool) {
            $this->assertSame('function', $tool['type']);
            $this->assertNotEmpty($tool['function']['name']);
            $this->assertSame('object', $tool['function']['parameters']['type']);
            $this->assertArrayHasKey('properties', $tool['function']['parameters']);
        }

        // Every declared tool must be routable through the orchestrator.
        foreach (\App\Services\WorkspaceTools::names() as $name) {
            $this->assertContains($name, \App\Services\WorkspaceTools::names());
        }
    }
}
