<?php

namespace App\Http\Controllers;

use App\Jobs\SyncGoogleSheetJob;
use App\Models\Category;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Task;
use App\Services\AiOrchestrator;
use App\Services\GoogleSheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON endpoints that hydrate the Blade board without a JS build step.
 */
class BoardController extends Controller
{
    public function __construct(
        private readonly AiOrchestrator $orchestrator,
        private readonly GoogleSheetService $sheets,
    ) {}

    public function projects(): JsonResponse
    {
        $projects = Project::query()
            ->with(['category', 'modules'])
            ->withCount('modules')
            ->orderBy('order_index')
            ->latest('updated_at')
            ->get();

        return response()->json([
            'projects' => $projects->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'category_id' => $project->category_id,
                'category' => $project->category ? [
                    'id' => $project->category->id,
                    'name' => $project->category->name,
                ] : null,
                'my_role' => $project->my_role,
                'department' => $project->department,
                'target_user' => $project->target_user,
                'project_owner' => $project->project_owner,
                'description' => $project->description,
                'status' => $project->status->value,
                'status_label' => $project->status->label(),
                'status_class' => $project->status->chipClass(),
                'doc_link' => $project->doc_link,
                'progress' => $project->progress(),
                'modules_count' => $project->modules_count,
                'modules_done' => $project->modules->where('status', \App\Enums\ItemStatus::DONE)->count(),
                'sheet_row_index' => $project->sheet_row_index,
                'order_index' => $project->order_index,
                'updated_at' => $project->updated_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'google_credentials_present' => $this->sheets->credentialsPresent(),
            ],
        ]);
    }

    /* ------------------------------------------------------------ categories */

    public function categories(): JsonResponse
    {
        $categories = Category::query()
            ->withCount('projects')
            ->orderBy('order_index')
            ->orderBy('name')
            ->get();

        return response()->json([
            'categories' => $categories->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'color' => $category->color,
                'order_index' => $category->order_index,
                'projects_count' => $category->projects_count,
            ])->all(),
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:32'],
        ]);

        $name = trim($validated['name']);

        $category = Category::firstOrCreate(
            ['slug' => \Illuminate\Support\Str::slug($name)],
            [
                'name' => $name,
                'color' => $validated['color'] ?? 'neutral',
                'order_index' => (int) (Category::max('order_index') ?? -1) + 1,
            ]
        );

        return response()->json([
            'status' => 'success',
            'category' => ['id' => $category->id, 'name' => $category->name],
        ], $category->wasRecentlyCreated ? 201 : 200);
    }

    public function updateCategory(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:32'],
            'order_index' => ['nullable', 'integer'],
        ]);

        if (filled($validated['name'] ?? null)) {
            $category->name = trim($validated['name']);
            $category->slug = \Illuminate\Support\Str::slug($category->name);
        }

        if (filled($validated['color'] ?? null)) {
            $category->color = $validated['color'];
        }

        if (array_key_exists('order_index', $validated) && $validated['order_index'] !== null) {
            $category->order_index = (int) $validated['order_index'];
        }

        $category->save();

        return response()->json([
            'status' => 'success',
            'category' => ['id' => $category->id, 'name' => $category->name],
        ]);
    }

    public function destroyCategory(Category $category): JsonResponse
    {
        $name = $category->name;
        $unfiled = $category->projects()->count();

        // Projects survive: the FK is nullOnDelete, they just lose the label.
        $category->delete();

        return response()->json([
            'status' => 'success',
            'deleted_category' => $name,
            'unfiled_projects' => $unfiled,
        ]);
    }

    /**
     * Assign a project to a category from the UI. Passing null unfiles it.
     */
    public function updateProject(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'string', 'exists:categories,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string'],
            'my_role' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'target_user' => ['nullable', 'string', 'max:255'],
            'project_owner' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'doc_link' => ['nullable', 'string', 'max:2048'],
            'order_index' => ['nullable', 'integer'],
        ]);

        $attributes = [];

        if (array_key_exists('category_id', $validated)) {
            $attributes['category_id'] = $validated['category_id'];
        }

        foreach (['name', 'my_role', 'department', 'target_user', 'project_owner', 'description', 'doc_link'] as $field) {
            if (filled($validated[$field] ?? null)) {
                $attributes[$field] = trim((string) $validated[$field]);
            }
        }

        if (filled($validated['status'] ?? null)) {
            $attributes['status'] = \App\Enums\ItemStatus::normalize($validated['status'])->value;
        }

        if (array_key_exists('order_index', $validated) && $validated['order_index'] !== null) {
            $attributes['order_index'] = (int) $validated['order_index'];
        }

        $project->fill($attributes)->save();

        return response()->json([
            'status' => 'success',
            'project' => ['id' => $project->id, 'category_id' => $project->category_id],
        ]);
    }

    /**
     * Reorder projects by an explicit, complete list of ids.
     */
    public function reorderProjects(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_ids' => ['required', 'array', 'min:1'],
            'project_ids.*' => ['string'],
        ]);

        $existing = Project::pluck('id')->all();
        $applied = 0;

        \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $existing, &$applied): void {
            foreach (array_values($validated['project_ids']) as $position => $id) {
                if (! in_array((string) $id, $existing, true)) {
                    continue;
                }

                Project::whereKey($id)->update(['order_index' => $position, 'updated_at' => now()]);
                $applied++;
            }
        });

        return response()->json(['status' => 'success', 'reordered' => $applied]);
    }

    public function project(Project $project): JsonResponse
    {
        $project->load(['modules' => fn ($q) => $q->orderBy('order_index')]);

        return response()->json([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'my_role' => $project->my_role,
                'department' => $project->department,
                'target_user' => $project->target_user,
                'project_owner' => $project->project_owner,
                'description' => $project->description,
                'status' => $project->status->value,
                'status_label' => $project->status->label(),
                'status_class' => $project->status->chipClass(),
                'doc_link' => $project->doc_link,
                'progress' => $project->progress(),
                'updated_at' => $project->updated_at?->toIso8601String(),
            ],
            'modules' => $project->modules->map(fn ($module) => [
                'id' => $module->id,
                'title' => $module->title,
                'description' => $module->description,
                'status' => $module->status->value,
                'status_label' => $module->status->label(),
                'status_class' => $module->status->chipClass(),
                'priority' => $module->priority->value,
                'priority_label' => $module->priority->label(),
                'priority_class' => $module->priority->chipClass(),
                'due_date' => $module->due_date?->toDateString(),
                'order_index' => $module->order_index,
            ])->all(),
        ]);
    }

    public function tasks(): JsonResponse
    {
        // Manual ordering wins; status/priority only break ties for un-ordered items.
        $tasks = Task::query()
            ->orderBy('order_index')
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")
            ->latest('updated_at')
            ->get();

        return response()->json([
            'tasks' => $tasks->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'notes' => $task->notes,
                'status' => $task->status->value,
                'status_label' => $task->status->label(),
                'status_class' => $task->status->chipClass(),
                'priority' => $task->priority->value,
                'priority_label' => $task->priority->label(),
                'priority_class' => $task->priority->chipClass(),
                'due_date' => $task->due_date?->toDateString(),
                'order_index' => $task->order_index,
                'updated_at' => $task->updated_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function summary(): JsonResponse
    {
        return response()->json($this->orchestrator->boardSnapshot());
    }

    /**
     * Global search across projects, modules and tasks.
     *
     * Uses LIKE rather than full-text search so the same code runs on SQLite
     * (tests) and PostgreSQL (production) without a migration.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:120'],
        ]);

        $term = trim($validated['q']);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $projects = Project::query()
            ->with('category')
            ->where(function ($query) use ($like): void {
                $query->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('department', 'like', $like)
                    ->orWhere('project_owner', 'like', $like);
            })
            ->limit(10)
            ->get();

        $modules = ProjectModule::query()
            ->with('project:id,name')
            ->where(function ($query) use ($like): void {
                $query->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like);
            })
            ->limit(10)
            ->get();

        $tasks = Task::query()
            ->where(function ($query) use ($like): void {
                $query->where('title', 'like', $like)
                    ->orWhere('notes', 'like', $like);
            })
            ->limit(10)
            ->get();

        $categories = Category::query()
            ->where('name', 'like', $like)
            ->limit(5)
            ->get();

        return response()->json([
            'query' => $term,
            'results' => [
                'categories' => $categories->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                ])->all(),
                'projects' => $projects->map(fn (Project $project) => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'category' => $project->category?->name,
                    'status_label' => $project->status->label(),
                    'status_class' => $project->status->chipClass(),
                ])->all(),
                'modules' => $modules->map(fn (ProjectModule $module) => [
                    'id' => $module->id,
                    'title' => $module->title,
                    'project_id' => $module->project_id,
                    'project_name' => $module->project?->name,
                    'status_label' => $module->status->label(),
                    'status_class' => $module->status->chipClass(),
                ])->all(),
                'tasks' => $tasks->map(fn (Task $task) => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status->value,
                    'status_label' => $task->status->label(),
                    'status_class' => $task->status->chipClass(),
                ])->all(),
            ],
        ]);
    }

    /**
     * Create a project from the UI. Sheet-managed rows are never touched here;
     * this only ever inserts a manual project (sheet_source_id stays null).
     */
    public function storeProject(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'string', 'exists:categories,id'],
            'my_role' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'target_user' => ['nullable', 'string', 'max:255'],
            'project_owner' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'doc_link' => ['nullable', 'string', 'max:2048'],
        ]);

        $validated['status'] = \App\Enums\ItemStatus::normalize($validated['status'] ?? null)->value;
        $validated['order_index'] = (int) (Project::max('order_index') ?? -1) + 1;

        $project = Project::create($validated);

        return response()->json(['status' => 'success', 'project' => ['id' => $project->id, 'name' => $project->name]], 201);
    }

    /**
     * Append a single module to a project from the detail panel.
     */
    public function storeModule(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
        ]);

        $module = ProjectModule::create([
            'project_id' => $project->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => \App\Enums\ItemStatus::normalize($validated['status'] ?? null)->value,
            'priority' => \App\Enums\ItemPriority::normalize($validated['priority'] ?? null)->value,
            'due_date' => $validated['due_date'] ?? null,
            'order_index' => (int) ($project->modules()->max('order_index') ?? -1) + 1,
        ]);

        return response()->json([
            'status' => 'success',
            'module' => ['id' => $module->id, 'title' => $module->title],
        ], 201);
    }

    /**
     * Toggle a module's completion from the detail panel checkbox.
     */
    public function updateModule(Request $request, ProjectModule $module): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
        ]);

        $attributes = [];

        if (array_key_exists('status', $validated) && $validated['status'] !== null) {
            $attributes['status'] = \App\Enums\ItemStatus::normalize($validated['status'])->value;
        }

        if (filled($validated['title'] ?? null)) {
            $attributes['title'] = $validated['title'];
        }

        if (array_key_exists('description', $validated) && $validated['description'] !== null) {
            $attributes['description'] = $validated['description'];
        }

        if (filled($validated['priority'] ?? null)) {
            $attributes['priority'] = \App\Enums\ItemPriority::normalize($validated['priority'])->value;
        }

        if (array_key_exists('due_date', $validated)) {
            $attributes['due_date'] = $validated['due_date'];
        }

        $module->fill($attributes)->save();

        return response()->json([
            'status' => 'success',
            'module' => ['id' => $module->id, 'status' => $module->status->value],
        ]);
    }

    public function destroyModule(ProjectModule $module): JsonResponse
    {
        $title = $module->title;
        $module->delete();

        return response()->json(['status' => 'success', 'deleted_module' => $title]);
    }

    /**
     * Reorder the modules of one project by an explicit, complete list of ids.
     */
    public function reorderModules(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'module_ids' => ['required', 'array', 'min:1'],
            'module_ids.*' => ['string'],
        ]);

        // Scope to this project so a stray id can never reorder another board,
        // and close gaps so a skipped id leaves no holes in the sequence.
        $owned = $project->modules()->pluck('id')->all();
        $map = [];
        $position = 0;

        foreach (array_values($validated['module_ids']) as $id) {
            if (! in_array((string) $id, $owned, true)) {
                continue;
            }

            $map[(string) $id] = $position++;
        }

        ProjectModule::reorderByIds($map);

        return response()->json(['status' => 'success', 'reordered' => count($map)]);
    }

    /**
     * Reorder the whole task list by an explicit, complete list of ids.
     */
    public function reorderTasks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['string'],
        ]);

        $existing = Task::pluck('id')->all();
        $map = [];
        $position = 0;

        foreach (array_values($validated['task_ids']) as $id) {
            if (! in_array((string) $id, $existing, true)) {
                continue;
            }

            $map[(string) $id] = $position++;
        }

        Task::reorderByIds($map);

        return response()->json(['status' => 'success', 'reordered' => count($map)]);
    }

    /**
     * Create an ad-hoc task from the Tasks menu.
     */
    public function storeTask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
        ]);

        $task = Task::create([
            'title' => $validated['title'],
            'notes' => $validated['notes'] ?? null,
            'status' => \App\Enums\ItemStatus::normalize($validated['status'] ?? null)->value,
            'priority' => \App\Enums\ItemPriority::normalize($validated['priority'] ?? null)->value,
            'due_date' => $validated['due_date'] ?? null,
            'order_index' => (int) (Task::max('order_index') ?? -1) + 1,
        ]);

        return response()->json([
            'status' => 'success',
            'task' => ['id' => $task->id, 'title' => $task->title],
        ], 201);
    }

    /**
     * Toggle status or edit an existing task.
     */
    public function updateTask(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
        ]);

        $attributes = [];

        if (! empty($validated['title'])) {
            $attributes['title'] = $validated['title'];
        }

        if (array_key_exists('notes', $validated) && $validated['notes'] !== null) {
            $attributes['notes'] = $validated['notes'];
        }

        if (array_key_exists('status', $validated) && $validated['status'] !== null) {
            $attributes['status'] = \App\Enums\ItemStatus::normalize($validated['status'])->value;
        }

        if (! empty($validated['priority'])) {
            $attributes['priority'] = \App\Enums\ItemPriority::normalize($validated['priority'])->value;
        }

        if (array_key_exists('due_date', $validated)) {
            $attributes['due_date'] = $validated['due_date'];
        }

        $task->fill($attributes)->save();

        return response()->json([
            'status' => 'success',
            'task' => ['id' => $task->id, 'status' => $task->status->value],
        ]);
    }

    public function destroyTask(Task $task): JsonResponse
    {
        $task->delete();

        return response()->json(['status' => 'success', 'deleted_id' => $task->id]);
    }

    public function sync(Request $request): JsonResponse
    {
        // Dispatching keeps the HTTP request fast; the queue worker does the work.
        SyncGoogleSheetJob::dispatch();

        $present = $this->sheets->credentialsPresent();

        return response()->json([
            'status' => $present ? 'success' : 'skipped',
            'message' => $present
                ? 'Sinkronisasi Google Sheets masuk ke queue.'
                : 'Credentials belum ada; sync dilewati.',
            'queued' => true,
        ]);
    }
}
