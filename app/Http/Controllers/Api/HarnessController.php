<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncGoogleSheetJob;
use App\Models\Category;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\SheetSource;
use App\Models\Task;
use App\Services\AiOrchestrator;
use App\Services\GoogleSheetService;
use App\Services\ObsidianVaultService;
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
        private readonly ObsidianVaultService $vault,
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
            'last_synced_at' => SheetSource::query()->max('last_synced_at'),
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
            'order_index' => ['nullable', 'integer'],
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

    /**
     * POST /api/harness/categories/reorder
     */
    public function reorderCategories(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['string'],
        ]);

        return $this->tool('reorder_categories', ['category_ids' => $validated['category_ids']]);
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

        // Through the tool layer like every other mutation. It used to duplicate
        // the skip-unknown-and-close-gaps logic inline, which meant the ordering
        // algorithm had two implementations that could drift apart.
        return $this->tool('reorder_projects', ['project_ids' => $validated['project_ids']]);
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
            // Routed through the tool layer rather than a raw
            // `$project->modules()->delete()`, so clearing a project's modules
            // has exactly one implementation and shows up as a tool call.
            $cleared = $this->tool('delete_module', [
                'all' => true,
                'where' => ['project_id' => $project->id],
            ]);

            if ($cleared->getStatusCode() !== 200) {
                return $cleared;
            }
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

    // --------------------------------------------------------------- bulk ops

    /*
     * WHY these endpoints exist next to the single-row ones: the tools already
     * accept a target selector (ids > `all` + `where` > single id), so a caller
     * without an LLM in front of it needs a transport for the same vocabulary.
     * Each method below only validates and forwards -- the selection rule itself
     * lives in AiOrchestrator::resolveTargets(), so there is exactly one
     * implementation of "which rows does this touch".
     */

    public function bulkCategories(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'string'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['string'],
            'name' => ['nullable', 'string', 'max:255'],
            'all' => ['nullable', 'boolean'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        return $this->tool('delete_category', $this->bulkArguments($validated, [
            'category_id', 'category_ids', 'name',
        ]));
    }

    public function bulkProjects(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:update,delete'],
            'project_id' => ['nullable', 'string'],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['string'],
            'all' => ['nullable', 'boolean'],
            'where' => ['nullable', 'array'],
            'where.category_id' => ['nullable', 'string'],
            'where.status' => ['nullable', 'string'],
            'confirm' => ['nullable', 'boolean'],
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

        return $this->tool(
            $validated['action'] === 'update' ? 'update_project' : 'delete_project',
            $this->bulkArguments($validated, [
                'project_id', 'project_ids', 'name', 'category_id', 'category_name', 'my_role',
                'department', 'target_user', 'project_owner', 'description', 'status',
                'doc_link', 'order_index',
            ]),
        );
    }

    public function bulkModules(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:update,delete'],
            'module_id' => ['nullable', 'string'],
            'module_ids' => ['nullable', 'array'],
            'module_ids.*' => ['string'],
            'all' => ['nullable', 'boolean'],
            'where' => ['nullable', 'array'],
            'where.project_id' => ['nullable', 'string'],
            'where.status' => ['nullable', 'string'],
            'where.priority' => ['nullable', 'string'],
            'confirm' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
            'due_date_shift_days' => ['nullable', 'integer'],
            'move_to_project_id' => ['nullable', 'string', 'exists:projects,id'],
        ]);

        return $this->tool(
            $validated['action'] === 'update' ? 'update_module' : 'delete_module',
            $this->bulkArguments($validated, [
                'module_id', 'module_ids', 'title', 'description', 'status', 'priority',
                'due_date', 'due_date_shift_days', 'move_to_project_id',
            ]),
        );
    }

    public function bulkTasks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:update,delete'],
            'task_id' => ['nullable', 'string'],
            'task_ids' => ['nullable', 'array'],
            'task_ids.*' => ['string'],
            'all' => ['nullable', 'boolean'],
            'where' => ['nullable', 'array'],
            'where.status' => ['nullable', 'string'],
            'where.priority' => ['nullable', 'string'],
            'confirm' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
            'due_date_shift_days' => ['nullable', 'integer'],
        ]);

        return $this->tool(
            $validated['action'] === 'update' ? 'update_task' : 'delete_task',
            $this->bulkArguments($validated, [
                'task_id', 'task_ids', 'title', 'notes', 'status', 'priority',
                'due_date', 'due_date_shift_days',
            ]),
        );
    }

    // ---------------------------------------------------------- obsidian vault

    /**
     * GET /api/harness/vault
     *
     * Shape of the second brain: whether it is mounted at all, how many notes,
     * which folders and tags exist. An agent should call this first, because
     * `available: false` means every other vault route is empty rather than
     * broken.
     */
    public function vault(): JsonResponse
    {
        return response()->json($this->vault->index());
    }

    /**
     * GET /api/harness/vault/notes?folder=&q=&limit=
     * Note headers, newest first. `q` matches titles and paths; body text is
     * the job of /vault/search.
     */
    public function vaultNotes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folder' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return response()->json([
            'available' => $this->vault->available(),
            'path' => $this->vault->path(),
            'notes' => $this->vault->notes(
                $validated['folder'] ?? null,
                $validated['q'] ?? null,
                (int) ($validated['limit'] ?? 200),
            ),
        ]);
    }

    /**
     * GET /api/harness/vault/search?q=&limit=
     * Full-text search with a snippet per hit.
     */
    public function vaultSearch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($this->vault->search($validated['q'], (int) ($validated['limit'] ?? 50)));
    }

    /**
     * GET /api/harness/vault/note?path=Folder/Note.md
     * One note: body, frontmatter, tags, outgoing wikilinks and backlinks.
     */
    public function vaultNote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
        ]);

        $note = $this->vault->note($validated['path']);

        if ($note === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Catatan tidak ditemukan di vault.',
            ], 404);
        }

        return response()->json(['status' => 'success', 'note' => $note]);
    }

    // --------------------------------------------------------------- helpers

    /**
     * Forward the fields a caller actually sent, so a tool's own defaults are
     * never overwritten with a null the request merely omitted.
     *
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function bulkArguments(array $validated, array $fields): array
    {
        $arguments = [];

        foreach (array_merge($fields, ['all', 'where', 'confirm']) as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                $arguments[$field] = $validated[$field];
            }
        }

        return $arguments;
    }

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
