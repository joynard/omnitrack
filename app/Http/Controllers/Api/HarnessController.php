<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncGoogleSheetJob;
use App\Models\Category;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Task;
use App\Services\AiOrchestrator;
use App\Services\GoogleSheetService;
use App\Services\WorkspaceTools;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Agentic bridge for DeepSeek Harness.
 *
 * Every route is protected by the `harness.token` middleware. All mutations are
 * funnelled through AiOrchestrator::callTool(), so an agent, the AI palette and
 * the Blade UI share exactly one validated implementation. Responses are kept
 * compact so they can be injected straight into an LLM context window.
 */
class HarnessController extends Controller
{
    public function __construct(
        private readonly AiOrchestrator $orchestrator,
        private readonly GoogleSheetService $sheets,
    ) {}

    /**
     * GET /api/harness/context
     */
    public function context(): JsonResponse
    {
        $snapshot = $this->orchestrator->boardSnapshot();

        $snapshot['sync'] = [
            'google_credentials_present' => $this->sheets->credentialsPresent(),
            'credentials_path' => $this->sheets->credentialsPath(),
            'last_synced_at' => \App\Models\SheetSource::query()->max('last_synced_at'),
        ];

        return response()->json($snapshot);
    }

    /**
     * GET /api/harness/tools
     * Lets an agent discover the mutation surface at runtime.
     */
    public function tools(): JsonResponse
    {
        return response()->json([
            'tools' => array_map(fn (array $tool) => [
                'name' => $tool['function']['name'],
                'description' => $tool['function']['description'],
                'parameters' => $tool['function']['parameters'],
            ], WorkspaceTools::all()),
        ]);
    }

    /**
     * POST /api/harness/agent
     * Natural-language instruction handled by the full-control agent.
     */
    public function agent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:8000'],
            'project_id' => ['nullable', 'string', 'exists:projects,id'],
        ]);

        $result = $this->orchestrator->agent($validated['prompt'], $validated);

        return response()->json($result, $result['status'] === 'error' ? 422 : 200);
    }

    // ------------------------------------------------------------ categories

    public function categories(): JsonResponse
    {
        return response()->json([
            'categories' => Category::query()
                ->withCount('projects')
                ->orderBy('order_index')
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'order_index' => $category->order_index,
                    'projects_count' => $category->projects_count,
                ])->all(),
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        return $this->tool('create_category', $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:32'],
        ]), 201);
    }

    public function updateCategory(Request $request, Category $category): JsonResponse
    {
        $arguments = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'new_name' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:32'],
            'order_index' => ['nullable', 'integer'],
        ]);

        // Body `name` is treated as the new label, matching the palette's verbs.
        $arguments['category_id'] = $category->id;
        $arguments['new_name'] ??= $arguments['name'] ?? null;

        return $this->tool('update_category', $arguments);
    }

    public function destroyCategory(Category $category): JsonResponse
    {
        return $this->tool('delete_category', ['category_id' => $category->id]);
    }

    // -------------------------------------------------------------- projects

    /**
     * POST /api/harness/projects
     * Creates a project, or updates it when `id` is supplied.
     */
    public function upsertProject(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'string', 'exists:projects,id'],
            'name' => ['required_without:id', 'nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'string', 'exists:categories,id'],
            'category_name' => ['nullable', 'string', 'max:255'],
            'my_role' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'target_user' => ['nullable', 'string', 'max:255'],
            'project_owner' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'doc_link' => ['nullable', 'string', 'max:2048'],
            'order_index' => ['nullable', 'integer'],
        ]);

        if (filled($validated['id'] ?? null)) {
            $validated['project_id'] = $validated['id'];

            return $this->tool('update_project', $validated);
        }

        return $this->tool('create_project', $validated, 201);
    }

    /**
     * POST /api/harness/projects/{project}
     */
    public function updateProject(Request $request, Project $project): JsonResponse
    {
        $arguments = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'string', 'exists:categories,id'],
            'category_name' => ['nullable', 'string', 'max:255'],
            'my_role' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'target_user' => ['nullable', 'string', 'max:255'],
            'project_owner' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'doc_link' => ['nullable', 'string', 'max:2048'],
            'order_index' => ['nullable', 'integer'],
        ]);

        $arguments['project_id'] = $project->id;

        return $this->tool('update_project', $arguments);
    }

    /**
     * DELETE /api/harness/projects/{project}
     * Removes the project and, by cascade, its modules.
     */
    public function destroyProject(Project $project): JsonResponse
    {
        return $this->tool('delete_project', ['project_id' => $project->id]);
    }

    /**
     * POST /api/harness/projects/reorder
     */
    public function reorderProjects(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_ids' => ['required', 'array', 'min:1'],
            'project_ids.*' => ['string'],
        ]);

        $existing = Project::pluck('id')->all();
        $map = [];
        $position = 0;

        foreach (array_values($validated['project_ids']) as $id) {
            if (! in_array((string) $id, $existing, true)) {
                continue;
            }

            $map[(string) $id] = $position++;
        }

        Project::reorderByIds($map);

        return response()->json(['status' => 'success', 'reordered' => count($map)]);
    }

    // --------------------------------------------------------------- modules

    /**
     * POST /api/harness/projects/{project}/modules
     * Batch-appends modules/todos to a project.
     */
    public function storeModules(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'modules' => ['required', 'array', 'min:1', 'max:100'],
            'modules.*.title' => ['required', 'string', 'max:255'],
            'modules.*.description' => ['nullable', 'string'],
            'modules.*.status' => ['nullable', 'string'],
            'modules.*.priority' => ['nullable', 'string'],
            'modules.*.due_date' => ['nullable', 'date'],
            'replace_existing' => ['nullable', 'boolean'],
        ]);

        if ($validated['replace_existing'] ?? false) {
            $project->modules()->delete();
        }

        return $this->tool('create_module', [
            'project_id' => $project->id,
            'modules' => $validated['modules'],
        ], 201);
    }

    /**
     * POST /api/harness/modules/{module}
     */
    public function updateModule(Request $request, ProjectModule $module): JsonResponse
    {
        $arguments = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
        ]);

        $arguments['module_id'] = $module->id;

        return $this->tool('update_module', $arguments);
    }

    /**
     * DELETE /api/harness/modules/{module}
     */
    public function destroyModule(ProjectModule $module): JsonResponse
    {
        return $this->tool('delete_module', ['module_id' => $module->id]);
    }

    /**
     * POST /api/harness/projects/{project}/modules/reorder
     */
    public function reorderModules(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'module_ids' => ['required', 'array', 'min:1'],
            'module_ids.*' => ['string'],
        ]);

        return $this->tool('reorder_modules', [
            'project_id' => $project->id,
            'module_ids' => $validated['module_ids'],
        ]);
    }

    // ----------------------------------------------------------------- tasks

    /**
     * POST /api/harness/tasks
     * Create a task, or dispatch create/update/delete via `action`.
     */
    public function tasks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:create,update,delete'],
            'id' => ['nullable', 'string', 'exists:tasks,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
        ]);

        return match ($validated['action']) {
            'create' => $this->tool('create_task', [
                'tasks' => [[
                    'title' => $validated['title'] ?? '',
                    'notes' => $validated['notes'] ?? null,
                    'status' => $validated['status'] ?? null,
                    'priority' => $validated['priority'] ?? null,
                    'due_date' => $validated['due_date'] ?? null,
                ]],
            ], 201),
            'update' => $this->tool('update_task', $this->taskArguments($validated)),
            'delete' => $this->tool('delete_task', ['task_id' => $validated['id'] ?? '']),
        };
    }

    /**
     * POST /api/harness/tasks/{task}
     */
    public function updateTask(Request $request, Task $task): JsonResponse
    {
        $arguments = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
        ]);

        $arguments['task_id'] = $task->id;

        return $this->tool('update_task', $arguments);
    }

    /**
     * DELETE /api/harness/tasks/{task}
     */
    public function destroyTask(Task $task): JsonResponse
    {
        return $this->tool('delete_task', ['task_id' => $task->id]);
    }

    /**
     * POST /api/harness/tasks/reorder
     */
    public function reorderTasks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['string'],
        ]);

        return $this->tool('reorder_tasks', ['task_ids' => $validated['task_ids']]);
    }

    /**
     * POST /api/harness/sync
     */
    public function sync(): JsonResponse
    {
        SyncGoogleSheetJob::dispatch();

        return response()->json([
            'status' => $this->sheets->credentialsPresent() ? 'success' : 'skipped',
            'message' => $this->sheets->credentialsPresent()
                ? 'SyncGoogleSheetJob queued.'
                : 'Credentials not injected yet; job queued but will skip ingestion.',
            'queued' => true,
        ]);
    }

    // --------------------------------------------------------------- helpers

    /**
     * Run a tool and shape the HTTP response.
     *
     * Validation failures inside a tool raise RuntimeException; a bad id raises
     * ModelNotFoundException. Both are client errors, so they map to 422 rather
     * than a 500.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function tool(string $name, array $arguments, int $status = 200): JsonResponse
    {
        try {
            $result = $this->orchestrator->callTool($name, $arguments);
        } catch (RuntimeException|ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Internal error: '.$e->getMessage()], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function taskArguments(array $validated): array
    {
        $arguments = ['task_id' => $validated['id'] ?? ''];

        foreach (['title', 'notes', 'status', 'priority', 'due_date'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                $arguments[$field] = $validated[$field];
            }
        }

        return $arguments;
    }
}
