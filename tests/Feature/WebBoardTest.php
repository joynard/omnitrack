<?php

namespace Tests\Feature;

use App\Jobs\SyncGoogleSheetJob;
use App\Models\AiPromptTemplate;
use App\Models\Category;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebBoardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * WHY this class needs a signed-in user: routes/web.php puts `/`,
     * `/projects`, `/board/*`, `/ai/*` and `/sync/*` behind `auth`. Without one,
     * every request here answers 302 to /login or 401, and the assertions then
     * fail on the redirect instead of on their actual subject -- which is what
     * this class did for its entire life.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_root_redirects_to_projects(): void
    {
        $this->get('/')->assertRedirect('/projects');
    }

    public function test_projects_and_tasks_pages_render(): void
    {
        $this->get('/projects')
            ->assertOk()
            ->assertSee('Projects')
            // The palette lives in layouts/app.blade.php. Its heading and the
            // header control were renamed from "AI" to "Apollo"; the literal
            // "AI Command Palette" this once asserted has not existed for a while.
            ->assertSee('Apollo')
            // The Ctrl + P modal and its assets must be present.
            ->assertSee('palette-overlay', escape: false)
            ->assertSee('js/palette.js', escape: false)
            // Collapsible cards: the lists stay open, everything else starts closed.
            ->assertSee('data-collapse="projects-list" data-collapse-default="open"', escape: false)
            ->assertSee('data-collapse="projects-progress" data-collapse-default="closed"', escape: false)
            ->assertSee('data-collapse="projects-add" data-collapse-default="closed"', escape: false);

        $this->get('/tasks')
            ->assertOk()
            ->assertSee('Tasks')
            ->assertSee('js/board-tasks.js', escape: false)
            ->assertSee('data-collapse="tasks-list" data-collapse-default="open"', escape: false)
            ->assertSee('data-collapse="tasks-add" data-collapse-default="closed"', escape: false)
            ->assertSee('data-collapse="tasks-progress" data-collapse-default="closed"', escape: false);
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

    public function test_ai_templates_endpoint_returns_the_stored_presets(): void
    {
        // WHY not $this->seed(): DatabaseSeeder deliberately creates only the
        // login account (see its class comment -- seeding fixtures made
        // `migrate:fresh --seed` wipe real projects), so presets are created here.
        AiPromptTemplate::factory()->create([
            'title' => 'Buat task baru dari teks',
            'prompt_payload' => 'Buat task: ',
            'action_type' => 'parse_task',
        ]);
        AiPromptTemplate::factory()->create([
            'title' => 'Sinkronkan Google Sheets sekarang',
            'prompt_payload' => '',
            'action_type' => 'trigger_sync',
        ]);

        $response = $this->getJson('/ai/templates')->assertOk();

        $titles = array_column($response->json('templates'), 'title');

        $this->assertContains('Buat task baru dari teks', $titles);
        $this->assertContains('Sinkronkan Google Sheets sekarang', $titles);
    }

    public function test_board_summary_reports_completion_per_status_and_per_category(): void
    {
        $owned = Category::factory()->create(['name' => 'Kantor']);
        $done = Project::factory()->create([
            'name' => 'Selesai',
            'category_id' => $owned->id,
            'status' => 'done',
        ]);
        Project::factory()->create([
            'name' => 'Jalan',
            'category_id' => $owned->id,
            'status' => 'in_progress',
        ]);
        Project::factory()->create(['name' => 'Antre', 'status' => 'pending']);

        ProjectModule::factory()->create(['project_id' => $done->id, 'title' => 'M1', 'status' => 'done']);
        ProjectModule::factory()->create(['project_id' => $done->id, 'title' => 'M2', 'status' => 'pending']);

        Task::factory()->create(['title' => 'T1', 'status' => 'done']);
        Task::factory()->create(['title' => 'T2', 'status' => 'in_progress']);

        $response = $this->getJson('/board/summary')->assertOk();

        // These are the keys the stat cards and the chart read directly.
        $response->assertJsonPath('stats.projects_total', 3)
            ->assertJsonPath('stats.projects_done', 1)
            ->assertJsonPath('stats.projects_in_progress', 1)
            ->assertJsonPath('stats.projects_pending', 1)
            ->assertJsonPath('stats.modules_total', 2)
            ->assertJsonPath('stats.modules_done', 1)
            ->assertJsonPath('stats.modules_pending', 1)
            ->assertJsonPath('stats.tasks_total', 2)
            ->assertJsonPath('stats.tasks_done', 1)
            ->assertJsonPath('stats.tasks_in_progress', 1)
            ->assertJsonPath('stats.tasks_pending', 0)
            // Kept for the existing "Task Terbuka" card.
            ->assertJsonPath('stats.tasks_open', 1);

        // Only categories that actually own a project get a bar.
        $this->assertCount(1, $response->json('category_progress'));
        $this->assertSame('Kantor', $response->json('category_progress.0.name'));
        $this->assertSame(2, $response->json('category_progress.0.projects_total'));
        $this->assertSame(1, $response->json('category_progress.0.projects_done'));
        $this->assertSame(1, $response->json('category_progress.0.projects_in_progress'));
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
