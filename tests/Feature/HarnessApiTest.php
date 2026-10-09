<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HarnessApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_harness_endpoints_reject_missing_token(): void
    {
        $this->getJson('/api/harness/context')
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthorized');
    }

    public function test_harness_endpoints_reject_wrong_token(): void
    {
        $this->withToken('wrong-token')
            ->getJson('/api/harness/context')
            ->assertStatus(401);
    }

    public function test_context_returns_board_summary_with_valid_token(): void
    {
        Project::factory()->create(['name' => 'Migration Project']);
        Task::factory()->create(['title' => 'Follow up vendor']);

        $response = $this->withToken('test-harness-token')->getJson('/api/harness/context');

        $response->assertOk()
            ->assertJsonPath('stats.projects_total', 1)
            ->assertJsonPath('stats.tasks_total', 1)
            ->assertJsonStructure(['stats', 'projects', 'tasks', 'sync']);
    }

    public function test_project_can_be_created_and_updated_through_harness(): void
    {
        $create = $this->withToken('test-harness-token')->postJson('/api/harness/projects', [
            'name' => 'New Harness Project',
            'my_role' => 'Lead',
            'status' => 'WIP jalan',
        ]);

        $create->assertStatus(201);
        $projectId = $create->json('data.project.id');

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'name' => 'New Harness Project',
            'status' => 'in_progress',
        ]);

        $this->withToken('test-harness-token')->postJson('/api/harness/projects', [
            'id' => $projectId,
            'status' => 'Selesai 100%',
        ])->assertOk();

        $this->assertDatabaseHas('projects', ['id' => $projectId, 'status' => 'done']);
    }

    public function test_modules_can_be_batch_created_for_a_project(): void
    {
        $project = Project::factory()->create();

        $this->withToken('test-harness-token')
            ->postJson("/api/harness/projects/{$project->id}/modules", [
                'modules' => [
                    ['title' => 'Modul A', 'priority' => 'P1 urgent'],
                    ['title' => 'Modul B', 'priority' => 'low'],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('created_count', 2);

        $this->assertDatabaseHas('project_modules', [
            'project_id' => $project->id,
            'title' => 'Modul A',
            'priority' => 'high',
        ]);
    }

    public function test_task_lifecycle_create_update_and_delete(): void
    {
        $create = $this->withToken('test-harness-token')->postJson('/api/harness/tasks', [
            'action' => 'create',
            'title' => 'Task from harness',
            'priority' => 'tinggi',
        ]);

        $create->assertStatus(201);
        $taskId = $create->json('data.tasks.0.id');

        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'priority' => 'high']);

        $this->withToken('test-harness-token')->postJson('/api/harness/tasks', [
            'action' => 'update',
            'id' => $taskId,
            'status' => 'selesai',
        ])->assertOk();

        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'status' => 'done']);

        $this->withToken('test-harness-token')->postJson('/api/harness/tasks', [
            'action' => 'delete',
            'id' => $taskId,
        ])->assertOk();

        $this->assertDatabaseMissing('tasks', ['id' => $taskId]);
    }

    public function test_sync_endpoint_queues_job_even_without_google_credentials(): void
    {
        $this->withToken('test-harness-token')
            ->postJson('/api/harness/sync')
            ->assertOk()
            ->assertJsonPath('status', 'skipped')
            ->assertJsonPath('queued', true);
    }

    public function test_tool_catalogue_is_exposed_to_agents(): void
    {
        $response = $this->withToken('test-harness-token')
            ->getJson('/api/harness/tools')
            ->assertOk();

        $names = array_column($response->json('tools'), 'name');

        $this->assertContains('create_project', $names);
        $this->assertContains('reorder_modules', $names);
        $this->assertContains('reorder_tasks', $names);
        $this->assertContains('delete_category', $names);
    }

    public function test_categories_can_be_managed_through_the_harness(): void
    {
        $created = $this->withToken('test-harness-token')
            ->postJson('/api/harness/categories', ['name' => 'Kuliah'])
            ->assertStatus(201)
            ->json('data.category');

        $this->withToken('test-harness-token')
            ->getJson('/api/harness/categories')
            ->assertOk()
            ->assertJsonPath('categories.0.name', 'Kuliah');

        $this->withToken('test-harness-token')
            ->postJson("/api/harness/categories/{$created['id']}", ['new_name' => 'Kuliah Semester 5'])
            ->assertOk();

        $this->assertDatabaseHas('categories', ['name' => 'Kuliah Semester 5']);

        $this->withToken('test-harness-token')
            ->deleteJson("/api/harness/categories/{$created['id']}")
            ->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $created['id']]);
    }

    public function test_project_can_be_filed_and_unfiled_via_category_name(): void
    {
        $project = Project::factory()->create(['name' => 'Skripsi']);

        $this->withToken('test-harness-token')
            ->postJson("/api/harness/projects/{$project->id}", ['category_name' => 'Kuliah'])
            ->assertOk();

        $project->refresh();
        $this->assertNotNull($project->category_id);
        $this->assertSame('Kuliah', $project->category->name);

        // Passing null explicitly unfiles it.
        $this->withToken('test-harness-token')
            ->postJson("/api/harness/projects/{$project->id}", ['category_id' => null])
            ->assertOk();

        $this->assertNull($project->fresh()->category_id);
    }

    public function test_modules_can_be_updated_deleted_and_reordered_via_harness(): void
    {
        $project = Project::factory()->create();
        $first = \App\Models\ProjectModule::factory()->create(['project_id' => $project->id, 'order_index' => 0]);
        $second = \App\Models\ProjectModule::factory()->create(['project_id' => $project->id, 'order_index' => 1]);

        $this->withToken('test-harness-token')
            ->postJson("/api/harness/modules/{$first->id}", ['status' => 'selesai', 'priority' => 'p1'])
            ->assertOk();

        $first->refresh();
        $this->assertSame('done', $first->status->value);
        $this->assertSame('high', $first->priority->value);

        $this->withToken('test-harness-token')
            ->postJson("/api/harness/projects/{$project->id}/modules/reorder", [
                'module_ids' => [$second->id, $first->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.reordered', 2);

        $this->assertSame(0, $second->fresh()->order_index);
        $this->assertSame(1, $first->fresh()->order_index);

        $this->withToken('test-harness-token')
            ->deleteJson("/api/harness/modules/{$first->id}")
            ->assertOk();

        $this->assertDatabaseMissing('project_modules', ['id' => $first->id]);
    }

    public function test_tasks_can_be_reordered_via_harness(): void
    {
        $a = Task::factory()->create(['order_index' => 0]);
        $b = Task::factory()->create(['order_index' => 1]);

        $this->withToken('test-harness-token')
            ->postJson('/api/harness/tasks/reorder', ['task_ids' => [$b->id, $a->id]])
            ->assertOk()
            ->assertJsonPath('data.reordered', 2);

        $this->assertSame(0, $b->fresh()->order_index);
        $this->assertSame(1, $a->fresh()->order_index);
    }

    public function test_invalid_tool_arguments_return_422_not_500(): void
    {
        $project = Project::factory()->create();

        // Empty modules array violates the tool's own validation.
        $this->withToken('test-harness-token')
            ->postJson("/api/harness/projects/{$project->id}/modules", ['modules' => []])
            ->assertStatus(422);

        // Unknown category id is a client error too.
        $this->withToken('test-harness-token')
            ->postJson("/api/harness/projects/{$project->id}", ['category_id' => 'not-a-real-id'])
            ->assertStatus(422);
    }

    public function test_project_can_be_deleted_with_its_modules(): void
    {
        $project = Project::factory()->create();
        \App\Models\ProjectModule::factory()->count(2)->create(['project_id' => $project->id]);

        $this->withToken('test-harness-token')
            ->deleteJson("/api/harness/projects/{$project->id}")
            ->assertOk();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseCount('project_modules', 0);
    }
}
