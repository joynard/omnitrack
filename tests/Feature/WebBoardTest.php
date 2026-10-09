<?php

namespace Tests\Feature;

use App\Jobs\SyncGoogleSheetJob;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_projects(): void
    {
        $this->get('/')->assertRedirect('/projects');
    }

    public function test_projects_and_tasks_pages_render(): void
    {
        $this->get('/projects')
            ->assertOk()
            ->assertSee('Projects')
            ->assertSee('AI Command Palette')
            // The Ctrl + P modal and its assets must be present.
            ->assertSee('palette-overlay', escape: false)
            ->assertSee('js/palette.js', escape: false);

        $this->get('/tasks')
            ->assertOk()
            ->assertSee('Tasks')
            ->assertSee('js/board-tasks.js', escape: false);
    }

    public function test_board_projects_endpoint_returns_project_payload(): void
    {
        Project::factory()->create(['name' => 'Visible Project']);

        $this->getJson('/board/projects')
            ->assertOk()
            ->assertJsonPath('projects.0.name', 'Visible Project')
            ->assertJsonStructure(['projects', 'meta' => ['google_credentials_present']]);
    }

    public function test_board_tasks_endpoint_orders_open_tasks_first(): void
    {
        Task::factory()->create(['title' => 'Done task', 'status' => 'done']);
        Task::factory()->create(['title' => 'Active task', 'status' => 'in_progress']);

        $response = $this->getJson('/board/tasks')->assertOk();

        $this->assertSame('Active task', $response->json('tasks.0.title'));
    }

    public function test_ai_templates_endpoint_returns_seeded_presets(): void
    {
        $this->seed();

        $response = $this->getJson('/ai/templates')->assertOk();

        $titles = array_column($response->json('templates'), 'title');

        $this->assertContains('Buat task baru dari teks', $titles);
        $this->assertContains('Sinkronkan Google Sheets sekarang', $titles);
    }

    public function test_ai_command_validates_action_type(): void
    {
        $this->postJson('/ai/command', ['action_type' => 'not_a_real_action'])
            ->assertStatus(422);
    }

    public function test_ai_command_rejects_empty_prompt_for_task_parsing(): void
    {
        $this->postJson('/ai/command', [
            'action_type' => 'parse_task',
            'prompt' => '   ',
        ])->assertStatus(422);
    }

    public function test_ai_summarize_board_works_without_deepseek_key(): void
    {
        config()->set('services.deepseek.api_key', null);

        Task::factory()->create(['title' => 'Open task']);

        $response = $this->postJson('/ai/command', [
            'action_type' => 'summarize_board',
            'prompt' => 'Ringkas board',
        ]);

        // Summarization degrades to local statistics instead of failing.
        $response->assertOk()->assertJsonPath('status', 'success');

        $this->assertStringContainsString('task', $response->json('message'));
    }

    public function test_sync_endpoint_queues_the_job(): void
    {
        Queue::fake();

        $this->postJson('/sync/google-sheet')
            ->assertOk()
            ->assertJsonPath('queued', true);

        Queue::assertPushed(SyncGoogleSheetJob::class);
    }

    public function test_local_project_and_task_crud_endpoints(): void
    {
        $this->postJson('/board/projects', ['name' => 'Local Project', 'status' => 'wip'])
            ->assertStatus(201);

        $this->assertDatabaseHas('projects', ['name' => 'Local Project', 'status' => 'in_progress']);

        $task = $this->postJson('/board/tasks', ['title' => 'Local Task', 'priority' => 'p1'])
            ->assertStatus(201)
            ->json('task');

        $this->assertDatabaseHas('tasks', ['id' => $task['id'], 'priority' => 'high']);

        $this->postJson("/board/tasks/{$task['id']}", ['status' => 'selesai'])->assertOk();
        $this->assertDatabaseHas('tasks', ['id' => $task['id'], 'status' => 'done']);

        $this->deleteJson("/board/tasks/{$task['id']}")->assertOk();
        $this->assertDatabaseMissing('tasks', ['id' => $task['id']]);
    }
}
