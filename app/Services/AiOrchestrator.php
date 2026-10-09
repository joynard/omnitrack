<?php

namespace App\Services;

use App\Enums\AiActionType;
use App\Enums\ItemPriority;
use App\Enums\ItemStatus;
use App\Jobs\SyncGoogleSheetJob;
use App\Models\AiPromptTemplate;
use App\Models\Category;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Orchestrates the AI command palette and turns a natural-language instruction
 * into concrete workspace mutations.
 *
 * Editing actions go through DeepSeek native tool calling (see WorkspaceTools),
 * so the model returns a structured call rather than prose the app must parse.
 * The model's choices are still validated here before touching the database.
 */
class AiOrchestrator
{
    public function __construct(
        private readonly DeepSeekService $deepseek,
        private readonly GoogleSheetService $sheets,
    ) {}

    /**
     * Execute one palette action.
     *
     * @param  array<string, mixed>  $input
     * @return array{status: string, message: string, data?: mixed}
     */
    public function run(AiActionType $action, string $prompt, array $input = []): array
    {
        return match ($action) {
            // Editing actions run the full workspace agent.
            AiActionType::PARSE_TASK,
            AiActionType::BREAKDOWN_PROJECT,
            AiActionType::AGENT => $this->agent($prompt, $input),

            AiActionType::SUMMARIZE_BOARD => $this->summarizeBoard($prompt, $input),
            AiActionType::TRIGGER_SYNC => $this->triggerSync($input),
        };
    }

    /**
     * Full-control agent: the model may create, edit, delete and reorder
     * categories, projects, modules and tasks in a single instruction.
     *
     * @param  array<string, mixed>  $input
     * @return array{status: string, message: string, data?: mixed}
     */
    public function agent(string $prompt, array $input = []): array
    {
        if (! $this->deepseek->configured()) {
            return [
                'status' => 'error',
                'message' => 'DEEPSEEK_API_KEY belum diisi, jadi perintah tidak bisa dijalankan.',
            ];
        }

        // Give the model the current board so it can resolve "project ini",
        // "modul kedua", existing IDs, and current ordering.
        $board = $this->boardSnapshot();

        $context = [
            'instruksi' => $prompt,
            'fokus' => array_filter([
                'project_id' => $input['project_id'] ?? null,
                'task_id' => $input['task_id'] ?? null,
            ]),
            'workspace' => $board,
        ];

        try {
            $call = $this->deepseek->chatWithTools(
                [
                    ['role' => 'system', 'content' => $this->agentSystemPrompt()],
                    ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_UNICODE)],
                ],
                WorkspaceTools::all()
            );
        } catch (Throwable $e) {
            return $this->aiFailure($e);
        }

        try {
            $result = $this->executeTool($call['name'], $call['arguments']);
        } catch (Throwable $e) {
            Log::error('Tool execution failed.', [
                'tool' => $call['name'],
                'arguments' => $call['arguments'],
                'exception' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Gagal menjalankan aksi "'.$call['name'].'": '.$e->getMessage(),
            ];
        }

        return [
            'status' => 'success',
            'message' => $result['message'],
            'data' => [
                'tool' => $call['name'],
                'arguments' => $call['arguments'],
                'result' => $result['data'] ?? null,
            ],
        ];
    }

    /**
     * Run a tool by name. Public so the HTTP layers (palette, board UI, harness
     * API) all mutate through one validated code path.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{message: string, data?: mixed}
     */
    public function callTool(string $name, array $arguments): array
    {
        return $this->executeTool($name, $arguments);
    }

    /**
     * Validate and execute one tool call.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{message: string, data?: mixed}
     */
    private function executeTool(string $name, array $arguments): array
    {
        if (! in_array($name, WorkspaceTools::names(), true)) {
            throw new RuntimeException('Tool "'.$name.'" tidak dikenal.');
        }

        return match ($name) {
            'create_category' => $this->toolCreateCategory($arguments),
            'update_category' => $this->toolUpdateCategory($arguments),
            'reorder_categories' => $this->toolReorderCategories($arguments),
            'delete_category' => $this->toolDeleteCategory($arguments),
            'create_project' => $this->toolCreateProject($arguments),
            'update_project' => $this->toolUpdateProject($arguments),
            'reorder_projects' => $this->toolReorderProjects($arguments),
            'delete_project' => $this->toolDeleteProject($arguments),
            'create_module' => $this->toolCreateModule($arguments),
            'update_module' => $this->toolUpdateModule($arguments),
            'delete_module' => $this->toolDeleteModule($arguments),
            'reorder_modules' => $this->toolReorderModules($arguments),
            'create_task' => $this->toolCreateTask($arguments),
            'update_task' => $this->toolUpdateTask($arguments),
            'delete_task' => $this->toolDeleteTask($arguments),
            'reorder_tasks' => $this->toolReorderTasks($arguments),
            default => throw new RuntimeException('Tool "'.$name.'" belum diimplementasikan.'),
        };
    }

    // ------------------------------------------------------------ categories

    private function toolCreateCategory(array $arguments): array
    {
        $name = $this->requireString($arguments, 'name');

        $color = filled($arguments['color'] ?? null) ? trim((string) $arguments['color']) : null;

        $category = Category::firstOrCreate(
            ['slug' => Str::slug($name)],
            array_filter([
                'name' => $name,
                // WHY the colour is omitted rather than set to null:
                // `categories.color` is NOT NULL with a default of "neutral", and
                // an explicit NULL is a constraint violation on every engine --
                // omitting the column is what lets the default win.
                'color' => $color,
                'order_index' => array_key_exists('order_index', $arguments) && $arguments['order_index'] !== null
                    ? (int) $arguments['order_index']
                    : (int) (Category::max('order_index') ?? -1) + 1,
            ], fn ($value) => $value !== null)
        );

        // The harness API accepts `color` on create, and dropping it silently for
        // an existing category is the "it looked like it worked" failure mode that
        // is worse than refusing the call outright. On this endpoint the meaning
        // is therefore "make sure this category is this colour".
        if (! $category->wasRecentlyCreated && $color !== null && $category->color !== $color) {
            $category->color = $color;
            $category->save();
        }

        return [
            'message' => 'Kategori "'.$category->name.'" '.($category->wasRecentlyCreated ? 'dibuat' : 'sudah ada').'.',
            'data' => ['category' => $this->categoryPayload($category)],
        ];
    }

    private function toolUpdateCategory(array $arguments): array
    {
        $category = $this->resolveCategoryRow($arguments);

        if (! $category) {
            throw new RuntimeException('Kategori tidak ditemukan. Sebutkan category_id atau nama kategorinya di `name`.');
        }

        if (filled($arguments['new_name'] ?? null)) {
            $newName = trim((string) $arguments['new_name']);
            $category->name = $newName;
            $category->slug = Str::slug($newName);
        }

        if (array_key_exists('color', $arguments) && $arguments['color'] !== null) {
            $category->color = trim((string) $arguments['color']);
        }

        if (array_key_exists('order_index', $arguments) && $arguments['order_index'] !== null) {
            $category->order_index = (int) $arguments['order_index'];
        }

        $category->save();

        return [
            'message' => 'Kategori diperbarui menjadi "'.$category->name.'".',
            'data' => ['category' => $this->categoryPayload($category)],
        ];
    }

    private function toolReorderCategories(array $arguments): array
    {
        $ids = $arguments['category_ids'] ?? null;

        if (! is_array($ids) || $ids === []) {
            throw new RuntimeException('category_ids kosong.');
        }

        $existing = Category::pluck('id')->all();
        $map = [];
        $position = 0;

        foreach (array_values($ids) as $id) {
            if (! in_array((string) $id, $existing, true)) {
                continue;
            }

            $map[(string) $id] = $position++;
        }

        if ($map === []) {
            throw new RuntimeException('Tidak ada category_id yang cocok dengan kategori di workspace.');
        }

        Category::reorderByIds($map);

        return [
            'message' => 'Urutan '.count($map).' kategori diperbarui.',
            'data' => ['reordered' => count($map)],
        ];
    }

    private function toolDeleteCategory(array $arguments): array
    {
        $categories = $this->categoryTargets($arguments);
        $names = $categories->pluck('name')->all();
        $unfiled = Project::query()->whereIn('category_id', $categories->modelKeys())->count();

        // nullOnDelete on the FK keeps the projects; they just lose the label.
        Category::query()->whereIn('id', $categories->modelKeys())->delete();

        $message = count($names) === 1
            ? 'Kategori "'.$names[0].'" dihapus.'
            : count($names).' kategori dihapus ('.implode(', ', array_slice($names, 0, 5))
                .(count($names) > 5 ? ', …' : '').').';

        return [
            'message' => $message
                .($unfiled > 0 ? ' '.$unfiled.' project menjadi tanpa kategori.' : ''),
            'data' => count($names) === 1
                ? ['deleted_category' => $names[0], 'deleted_categories' => $names, 'unfiled_projects' => $unfiled]
                : ['deleted_categories' => $names, 'unfiled_projects' => $unfiled],
        ];
    }

    // -------------------------------------------------------------- projects

    private function toolCreateProject(array $arguments): array
    {
        $attributes = $this->projectAttributes($arguments);
        $attributes['name'] = $this->requireString($arguments, 'name');
        $attributes['category_id'] = $this->resolveCategoryId($arguments);
        $attributes['order_index'] = (int) (Project::max('order_index') ?? -1) + 1;

        // Always set status explicitly: a freshly created model has not read the
        // database default back yet, so the enum cast would otherwise be null.
        $attributes['status'] ??= ItemStatus::PENDING->value;

        $project = Project::create($attributes);

        // Read back so database defaults (and the category relation) are present
        // in the response payload.
        $project = $project->fresh('category');

        return [
            'message' => 'Project "'.$project->name.'" dibuat'
                .($project->category ? ' di kategori "'.$project->category->name.'"' : '').'.',
            'data' => ['project' => $this->projectPayload($project)],
        ];
    }

    private function toolUpdateProject(array $arguments): array
    {
        $projects = $this->resolveTargets(
            $arguments,
            Project::query(),
            'project_id',
            'project_ids',
            'project',
            ['category_id', 'status'],
        );

        $attributes = $this->projectAttributes($arguments);

        if (filled($arguments['name'] ?? null)) {
            $attributes['name'] = trim((string) $arguments['name']);
        }

        if (array_key_exists('category_id', $arguments) || array_key_exists('category_name', $arguments)) {
            $attributes['category_id'] = $this->resolveCategoryId($arguments);
        }

        if (array_key_exists('order_index', $arguments) && $arguments['order_index'] !== null) {
            $attributes['order_index'] = (int) $arguments['order_index'];
        }

        if ($attributes === []) {
            throw new RuntimeException('Tidak ada perubahan yang diminta.');
        }

        Project::query()->whereIn('id', $projects->modelKeys())->update($attributes);

        // The single-project path keeps its original response shape so existing
        // callers see no difference; only the multi-row case gets a new one.
        if ($projects->count() === 1) {
            $project = $projects->first()->fresh('category');

            return [
                'message' => 'Project "'.$project->name.'" diperbarui.',
                'data' => ['project' => $this->projectPayload($project)],
            ];
        }

        return [
            'message' => $projects->count().' project diperbarui ('.implode(', ', array_keys($attributes)).').',
            'data' => ['updated_projects' => $projects->count(), 'attributes' => $attributes],
        ];
    }

    private function toolReorderProjects(array $arguments): array
    {
        $ids = $arguments['project_ids'] ?? null;

        if (! is_array($ids) || $ids === []) {
            throw new RuntimeException('project_ids kosong.');
        }

        $existing = Project::pluck('id')->all();
        $map = [];
        $position = 0;

        foreach (array_values($ids) as $id) {
            if (! in_array((string) $id, $existing, true)) {
                continue;
            }

            $map[(string) $id] = $position++;
        }

        if ($map === []) {
            throw new RuntimeException('Tidak ada project_id yang cocok dengan project di workspace.');
        }

        Project::reorderByIds($map);

        return [
            'message' => 'Urutan '.count($map).' project diperbarui.',
            'data' => ['reordered' => count($map)],
        ];
    }

    private function toolDeleteProject(array $arguments): array
    {
        $projects = $this->resolveTargets(
            $arguments,
            Project::query(),
            'project_id',
            'project_ids',
            'project',
            ['category_id', 'status'],
        );

        $names = $projects->pluck('name')->all();
        $modules = ProjectModule::query()->whereIn('project_id', $projects->modelKeys())->count();

        // Modules go with them via cascadeOnDelete on the FK.
        $deleted = Project::query()->whereIn('id', $projects->modelKeys())->delete();

        $message = count($names) === 1
            ? 'Project "'.$names[0].'" dihapus'
            : $deleted.' project dihapus ('.implode(', ', array_slice($names, 0, 5))
                .(count($names) > 5 ? ', …' : '').')';

        return [
            'message' => $message.($modules > 0 ? ' beserta '.$modules.' modulnya' : '').'.',
            'data' => count($names) === 1
                ? ['deleted_project' => $names[0], 'deleted_projects' => $names, 'deleted_modules' => $modules]
                : ['deleted_projects' => $names, 'deleted_modules' => $modules],
        ];
    }

    // --------------------------------------------------------------- modules

    private function toolCreateModule(array $arguments): array
    {
        $project = $this->resolveProject($arguments);
        $modules = $arguments['modules'] ?? null;

        if (! is_array($modules) || $modules === []) {
            throw new RuntimeException('Daftar modul kosong.');
        }

        $startIndex = (int) ($project->modules()->max('order_index') ?? -1) + 1;
        $created = [];

        foreach (array_values($modules) as $offset => $module) {
            $title = trim((string) ($module['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $created[] = ProjectModule::create([
                'project_id' => $project->id,
                'title' => $title,
                'description' => $this->nullable($module['description'] ?? null),
                'status' => ItemStatus::normalize($module['status'] ?? null)->value,
                'priority' => ItemPriority::normalize($module['priority'] ?? null)->value,
                'due_date' => $this->validDate($module['due_date'] ?? null),
                'order_index' => $startIndex + $offset,
            ]);
        }

        if ($created === []) {
            throw new RuntimeException('Tidak ada modul valid yang bisa dibuat.');
        }

        return [
            'message' => count($created).' modul ditambahkan ke "'.$project->name.'".',
            'data' => ['project_id' => $project->id, 'modules' => array_map($this->modulePayload(...), $created)],
        ];
    }

    private function toolUpdateModule(array $arguments): array
    {
        $modules = $this->resolveTargets(
            $arguments,
            ProjectModule::query(),
            'module_id',
            'module_ids',
            'modul',
            ['project_id', 'status', 'priority'],
        );

        $attributes = [];

        foreach (['title', 'description'] as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== null) {
                $attributes[$field] = trim((string) $arguments[$field]);
            }
        }

        if (array_key_exists('status', $arguments) && $arguments['status'] !== null) {
            $attributes['status'] = ItemStatus::normalize($arguments['status'])->value;
        }

        if (array_key_exists('priority', $arguments) && $arguments['priority'] !== null) {
            $attributes['priority'] = ItemPriority::normalize($arguments['priority'])->value;
        }

        if (array_key_exists('due_date', $arguments)) {
            if (array_key_exists('due_date_shift_days', $arguments)) {
                throw new RuntimeException('Pilih salah satu: `due_date` atau `due_date_shift_days`, bukan keduanya.');
            }

            $attributes['due_date'] = $this->validDate($arguments['due_date']);
        }

        $shiftDays = array_key_exists('due_date_shift_days', $arguments)
            ? (int) $arguments['due_date_shift_days']
            : null;

        $destination = null;

        if (filled($arguments['move_to_project_id'] ?? null)) {
            $destination = Project::whereKey($arguments['move_to_project_id'])->value('id');

            if ($destination === null) {
                throw new RuntimeException('Project tujuan tidak ditemukan.');
            }

            $destination = (string) $destination;
        }

        if ($attributes === [] && $shiftDays === null && $destination === null) {
            throw new RuntimeException('Tidak ada perubahan yang diminta.');
        }

        // A move and a per-row shift cannot be ONE mass UPDATE: the move needs a
        // distinct order_index per row (appended after the destination's current
        // maximum) and the shift needs each row's own date. Both go through the
        // model, so casts and timestamps behave as they do on the single-row path.
        if ($destination !== null || $shiftDays !== null) {
            $nextIndex = $destination === null
                ? null
                : (int) (ProjectModule::where('project_id', $destination)->max('order_index') ?? -1) + 1;

            DB::transaction(function () use ($modules, $attributes, $shiftDays, $destination, &$nextIndex): void {
                foreach ($modules as $module) {
                    $changes = $attributes;

                    if ($destination !== null) {
                        $changes['project_id'] = $destination;
                        $changes['order_index'] = $nextIndex++;
                    }

                    if ($shiftDays !== null && $module->due_date !== null) {
                        $changes['due_date'] = $module->due_date->copy()->addDays($shiftDays)->toDateString();
                    }

                    if ($changes !== []) {
                        $module->fill($changes)->save();
                    }
                }
            });
        } else {
            ProjectModule::query()->whereIn('id', $modules->modelKeys())->update($attributes);
        }

        if ($modules->count() === 1) {
            $module = $modules->first()->fresh();

            return [
                'message' => 'Modul "'.$module->title.'" diperbarui.',
                'data' => ['module' => $this->modulePayload($module)],
            ];
        }

        $described = array_keys($attributes);

        if ($shiftDays !== null) {
            $described[] = 'tenggat digeser '.$shiftDays.' hari';
        }

        if ($destination !== null) {
            $described[] = 'dipindah ke project '.$destination;
        }

        return [
            'message' => $modules->count().' modul diperbarui ('.implode(', ', $described).').',
            'data' => [
                'updated_modules' => $modules->count(),
                'attributes' => $attributes,
                'due_date_shift_days' => $shiftDays,
                'moved_to_project_id' => $destination,
            ],
        ];
    }

    private function toolDeleteModule(array $arguments): array
    {
        $modules = $this->resolveTargets(
            $arguments,
            ProjectModule::query(),
            'module_id',
            'module_ids',
            'modul',
            ['project_id', 'status', 'priority'],
        );

        $titles = $modules->pluck('title')->all();
        $deleted = ProjectModule::query()->whereIn('id', $modules->modelKeys())->delete();

        $message = count($titles) === 1
            ? 'Modul "'.$titles[0].'" dihapus.'
            : $deleted.' modul dihapus ('.implode(', ', array_slice($titles, 0, 5))
                .(count($titles) > 5 ? ', …' : '').').';

        return [
            'message' => $message,
            'data' => count($titles) === 1
                ? ['deleted_module' => $titles[0], 'deleted_modules' => $titles]
                : ['deleted_modules' => $titles],
        ];
    }

    private function toolReorderModules(array $arguments): array
    {
        $project = $this->resolveProject($arguments);
        $ids = $arguments['module_ids'] ?? null;

        if (! is_array($ids) || $ids === []) {
            throw new RuntimeException('module_ids kosong.');
        }

        // Only reorder modules that genuinely belong to this project, and close
        // any gaps so a skipped id cannot leave holes in the sequence.
        $owned = $project->modules()->pluck('id')->all();
        $map = [];
        $position = 0;

        foreach (array_values($ids) as $id) {
            if (! in_array((string) $id, $owned, true)) {
                continue;
            }

            $map[(string) $id] = $position++;
        }

        if ($map !== []) {
            ProjectModule::reorderByIds($map);
        }

        return [
            'message' => 'Urutan '.count($map).' modul di "'.$project->name.'" diperbarui.',
            'data' => ['project_id' => $project->id, 'reordered' => count($map)],
        ];
    }

    // ----------------------------------------------------------------- tasks

    private function toolCreateTask(array $arguments): array
    {
        $tasks = $arguments['tasks'] ?? null;

        // Tolerate a single task object instead of an array.
        if (is_array($tasks) && array_key_exists('title', $tasks) && ! array_is_list($tasks)) {
            $tasks = [$tasks];
        }

        if (! is_array($tasks) || $tasks === []) {
            throw new RuntimeException('Daftar task kosong.');
        }

        $startIndex = (int) (Task::max('order_index') ?? -1) + 1;
        $created = [];

        foreach (array_values($tasks) as $offset => $task) {
            $title = trim((string) ($task['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $created[] = Task::create([
                'title' => $title,
                'notes' => $this->nullable($task['notes'] ?? null),
                'status' => ItemStatus::normalize($task['status'] ?? null)->value,
                'priority' => ItemPriority::normalize($task['priority'] ?? null)->value,
                'due_date' => $this->validDate($task['due_date'] ?? null),
                'order_index' => $startIndex + $offset,
            ]);
        }

        if ($created === []) {
            throw new RuntimeException('Tidak ada task valid yang bisa dibuat.');
        }

        return [
            'message' => count($created).' task dibuat.',
            'data' => ['tasks' => array_map($this->taskPayload(...), $created)],
        ];
    }

    private function toolUpdateTask(array $arguments): array
    {
        $tasks = $this->resolveTargets(
            $arguments,
            Task::query(),
            'task_id',
            'task_ids',
            'task',
            ['status', 'priority'],
        );

        $attributes = [];

        foreach (['title', 'notes'] as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== null) {
                $attributes[$field] = trim((string) $arguments[$field]);
            }
        }

        if (array_key_exists('status', $arguments) && $arguments['status'] !== null) {
            $attributes['status'] = ItemStatus::normalize($arguments['status'])->value;
        }

        if (array_key_exists('priority', $arguments) && $arguments['priority'] !== null) {
            $attributes['priority'] = ItemPriority::normalize($arguments['priority'])->value;
        }

        if (array_key_exists('due_date', $arguments)) {
            if (array_key_exists('due_date_shift_days', $arguments)) {
                throw new RuntimeException('Pilih salah satu: `due_date` atau `due_date_shift_days`, bukan keduanya.');
            }

            $attributes['due_date'] = $this->validDate($arguments['due_date']);
        }

        $shiftDays = array_key_exists('due_date_shift_days', $arguments)
            ? (int) $arguments['due_date_shift_days']
            : null;

        if ($attributes === [] && $shiftDays === null) {
            throw new RuntimeException('Tidak ada perubahan yang diminta.');
        }

        // A per-row shift needs each row's own date, so it cannot be one mass
        // UPDATE. Going through the model keeps the date cast and the timestamp
        // behaviour identical to the single-row path.
        if ($shiftDays !== null) {
            DB::transaction(function () use ($tasks, $attributes, $shiftDays): void {
                foreach ($tasks as $task) {
                    $changes = $attributes;

                    if ($task->due_date !== null) {
                        $changes['due_date'] = $task->due_date->copy()->addDays($shiftDays)->toDateString();
                    }

                    if ($changes !== []) {
                        $task->fill($changes)->save();
                    }
                }
            });
        } else {
            Task::query()->whereIn('id', $tasks->modelKeys())->update($attributes);
        }

        if ($tasks->count() === 1) {
            $task = $tasks->first()->fresh();

            return [
                'message' => 'Task "'.$task->title.'" diperbarui.',
                'data' => ['task' => $this->taskPayload($task)],
            ];
        }

        $described = array_keys($attributes);

        if ($shiftDays !== null) {
            $described[] = 'tenggat digeser '.$shiftDays.' hari';
        }

        return [
            'message' => $tasks->count().' task diperbarui ('.implode(', ', $described).').',
            'data' => [
                'updated_tasks' => $tasks->count(),
                'attributes' => $attributes,
                'due_date_shift_days' => $shiftDays,
            ],
        ];
    }

    private function toolDeleteTask(array $arguments): array
    {
        $tasks = $this->resolveTargets(
            $arguments,
            Task::query(),
            'task_id',
            'task_ids',
            'task',
            ['status', 'priority'],
        );

        $titles = $tasks->pluck('title')->all();
        $deleted = Task::query()->whereIn('id', $tasks->modelKeys())->delete();

        $message = count($titles) === 1
            ? 'Task "'.$titles[0].'" dihapus.'
            : $deleted.' task dihapus ('.implode(', ', array_slice($titles, 0, 5))
                .(count($titles) > 5 ? ', …' : '').').';

        return [
            'message' => $message,
            'data' => count($titles) === 1
                ? ['deleted_task' => $titles[0], 'deleted_tasks' => $titles]
                : ['deleted_tasks' => $titles],
        ];
    }

    private function toolReorderTasks(array $arguments): array
    {
        $ids = $arguments['task_ids'] ?? null;

        if (! is_array($ids) || $ids === []) {
            throw new RuntimeException('task_ids kosong.');
        }

        $existing = Task::pluck('id')->all();
        $map = [];
        $position = 0;

        foreach (array_values($ids) as $id) {
            if (! in_array((string) $id, $existing, true)) {
                continue;
            }

            $map[(string) $id] = $position++;
        }

        if ($map !== []) {
            Task::reorderByIds($map);
        }

        return [
            'message' => 'Urutan '.count($map).' task diperbarui.',
            'data' => ['reordered' => count($map)],
        ];
    }

    // ----------------------------------------------------- action: summarize

    /**
     * "Summarize board" -> DeepSeek narrative + deterministic counts.
     *
     * @param  array<string, mixed>  $input
     * @return array{status: string, message: string, data?: mixed}
     */
    private function summarizeBoard(string $prompt, array $input): array
    {
        $summary = $this->boardSnapshot();
        $stats = $summary['stats'];

        $fallback = sprintf(
            '%d project (%d selesai), %d task (%d selesai), %d modul tertunda.',
            $stats['projects_total'],
            $stats['projects_done'],
            $stats['tasks_total'],
            $stats['tasks_done'],
            $stats['modules_pending'],
        );

        if (! $this->deepseek->configured()) {
            return [
                'status' => 'success',
                'message' => $fallback.' (AI tidak aktif: DEEPSEEK_API_KEY kosong.)',
                'data' => $summary,
            ];
        }

        try {
            $narrative = $this->deepseek->chat([
                ['role' => 'system', 'content' => 'Kamu Apollo, asisten manajemen proyek Omnitrack. Jawab singkat, padat, maksimal 5 poin, tanpa basa-basi.'],
                ['role' => 'user', 'content' => "Ringkas kondisi board berikut dan sebutkan 2 prioritas berikutnya.\n\nInstruksi: {$prompt}\n\nData: ".json_encode($summary, JSON_UNESCAPED_UNICODE)],
            ], ['max_tokens' => 700]);

            return [
                'status' => 'success',
                'message' => trim($narrative),
                'data' => $summary,
            ];
        } catch (Throwable $e) {
            // A summary is still useful without the model; degrade, don't fail.
            Log::warning('Board summarization fell back to local stats.', ['exception' => $e->getMessage()]);

            return [
                'status' => 'success',
                'message' => $fallback,
                'data' => $summary,
            ];
        }
    }

    // ---------------------------------------------------------- action: sync

    /**
     * "Sinkronkan Google Sheets sekarang" -> dispatches the queued job.
     *
     * @param  array<string, mixed>  $input
     * @return array{status: string, message: string, data?: mixed}
     */
    private function triggerSync(array $input): array
    {
        $job = new SyncGoogleSheetJob;
        $queueConnection = $input['queue_connection'] ?? null;

        if (is_string($queueConnection) && $queueConnection !== '') {
            dispatch($job)->onConnection($queueConnection);
        } else {
            dispatch($job);
        }

        $immediate = config('queue.default') === 'sync';
        $present = $this->sheets->credentialsPresent();

        if (! $present) {
            return [
                'status' => 'skipped',
                'message' => 'Sinkronisasi dijadwalkan, tapi Google credentials belum ada di storage/app/google/credentials.json.',
                'data' => ['queued' => true, 'credentials' => false],
            ];
        }

        return [
            'status' => 'success',
            'message' => $immediate
                ? 'Sinkronisasi Google Sheets selesai dijalankan.'
                : 'Sinkronisasi Google Sheets masuk ke queue worker.',
            'data' => ['queued' => ! $immediate, 'credentials' => true],
        ];
    }

    // ------------------------------------------------------------- snapshots

    /**
     * Count rows per status.
     *
     * @param  Collection<int, mixed>  $items  models carrying an ItemStatus cast
     * @return array{done: int, in_progress: int, pending: int}
     */
    private function statusCounts(Collection $items): array
    {
        $counts = ['done' => 0, 'in_progress' => 0, 'pending' => 0];

        foreach ($items as $item) {
            $counts[$item->status->value]++;
        }

        return $counts;
    }

    /**
     * Compact workspace snapshot used as model context and for summaries.
     *
     * @return array<string, mixed>
     */
    public function boardSnapshot(): array
    {
        $categories = Category::query()->orderBy('order_index')->orderBy('name')->get();

        $projects = Project::query()
            ->with(['category', 'modules' => fn ($q) => $q->orderBy('order_index')])
            ->orderBy('order_index')
            ->latest('updated_at')
            ->get();

        $tasks = Task::query()->orderBy('order_index')->get();

        // One flat module list, so module completion is a single pass instead of
        // a pass per project; the projects already carry their modules.
        $modules = $projects->flatMap(fn (Project $project) => $project->modules);

        $projectStatus = $this->statusCounts($projects);
        $moduleStatus = $this->statusCounts($modules);
        $taskStatus = $this->statusCounts($tasks);

        return [
            'stats' => [
                'categories_total' => $categories->count(),
                'projects_total' => $projects->count(),
                'projects_done' => $projectStatus['done'],
                'projects_in_progress' => $projectStatus['in_progress'],
                'projects_pending' => $projectStatus['pending'],
                'modules_total' => $modules->count(),
                'modules_done' => $moduleStatus['done'],
                'modules_in_progress' => $moduleStatus['in_progress'],
                'modules_pending' => $moduleStatus['pending'],
                'tasks_total' => $tasks->count(),
                'tasks_done' => $taskStatus['done'],
                'tasks_in_progress' => $taskStatus['in_progress'],
                'tasks_pending' => $taskStatus['pending'],
                'tasks_open' => $tasks->filter(fn (Task $t) => $t->status !== ItemStatus::DONE)->count(),
            ],
            'categories' => $categories->map(fn (Category $c) => $this->categoryPayload($c))->all(),
            // Per-category completion for the Projects page chart. Derived from the
            // already-loaded $projects rather than $category->projects, which would
            // be one extra query per category. Categories owning nothing are
            // dropped: an empty bar is noise, not information.
            'category_progress' => $categories
                ->map(function (Category $category) use ($projects): array {
                    $owned = $projects->where('category_id', $category->id);

                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                        'projects_total' => $owned->count(),
                        'projects_done' => $owned->filter(fn (Project $p) => $p->status === ItemStatus::DONE)->count(),
                        'projects_in_progress' => $owned->filter(fn (Project $p) => $p->status === ItemStatus::IN_PROGRESS)->count(),
                        'projects_pending' => $owned->filter(fn (Project $p) => $p->status === ItemStatus::PENDING)->count(),
                    ];
                })
                ->filter(fn (array $row) => $row['projects_total'] > 0)
                ->values()
                ->all(),
            'projects' => $projects->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'category_id' => $p->category_id,
                'category' => $p->category?->name,
                'my_role' => $p->my_role,
                'department' => $p->department,
                'status' => $p->status->value,
                'order_index' => $p->order_index,
                'progress' => $p->progress(),
                'modules' => $p->modules->map(fn (ProjectModule $m) => [
                    'id' => $m->id,
                    'title' => $m->title,
                    'status' => $m->status->value,
                    'priority' => $m->priority->value,
                    'order_index' => $m->order_index,
                    'due_date' => $m->due_date?->toDateString(),
                ])->all(),
            ])->all(),
            'tasks' => $tasks->map(fn (Task $t) => $this->taskPayload($t))->all(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return Collection<int, AiPromptTemplate>
     */
    public function templates(): Collection
    {
        return AiPromptTemplate::query()->orderBy('created_at')->get();
    }

    // --------------------------------------------------------------- helpers

    private function agentSystemPrompt(): string
    {
        return <<<'PROMPT'
        Kamu adalah Apollo, agen pengelola workspace personal Omnitrack.

        Kamu punya kontrol penuh: membuat, mengubah, menghapus, dan MENGATUR URUTAN
        kategori, project, modul (sub-todo), dan task.

        Aturan:
        - Panggil tepat satu tool yang paling sesuai dengan instruksi user.
        - Gunakan ID yang ada di data workspace. Jangan mengarang ID.
        - Untuk reorder, sertakan SEMUA id yang relevan dalam urutan baru yang lengkap.
        - Kalau user menyebut project/modul/task tanpa ID, cocokkan dari namanya di data workspace.
        - Kalau user menyebut nama kategori baru, pakai create_category lebih dulu atau isi category_name.
        - Jangan minta konfirmasi; langsung eksekusi instruksi.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function projectAttributes(array $arguments): array
    {
        $attributes = [];

        foreach (['my_role', 'department', 'target_user', 'project_owner', 'description', 'doc_link'] as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== null) {
                $attributes[$field] = trim((string) $arguments[$field]);
            }
        }

        if (array_key_exists('status', $arguments) && $arguments['status'] !== null) {
            $attributes['status'] = ItemStatus::normalize($arguments['status'])->value;
        }

        return $attributes;
    }

    /**
     * Accept either category_id or category_name, creating the category when a
     * name is supplied that does not exist yet.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function resolveCategoryId(array $arguments): ?string
    {
        if (filled($arguments['category_id'] ?? null)) {
            return (string) Category::whereKey($arguments['category_id'])->value('id') ?: null;
        }

        if (filled($arguments['category_name'] ?? null)) {
            $name = trim((string) $arguments['category_name']);

            return Category::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'order_index' => (int) (Category::max('order_index') ?? -1) + 1]
            )->id;
        }

        return null;
    }

    // ------------------------------------------------------- bulk targeting

    /**
     * The rows a bulk-capable tool should act on.
     *
     * Precedence is fixed here and advertised to the model in WorkspaceTools:
     * an explicit id list wins, then `all` narrowed by `where`, then one id.
     * Every path queries the database, so an id that does not exist is reported
     * instead of being silently ignored.
     *
     * @param  array<string, mixed>  $arguments
     * @param  Builder<*>  $query  unfiltered base query for the entity
     * @param  list<string>  $whereKeys  filter keys this entity accepts
     * @return EloquentCollection<int, Model>
     */
    private function resolveTargets(
        array $arguments,
        Builder $query,
        string $singleKey,
        string $pluralKey,
        string $label,
        array $whereKeys = [],
    ): EloquentCollection {
        $ids = $arguments[$pluralKey] ?? null;

        if (is_array($ids) && $ids !== []) {
            $found = $query->whereIn('id', array_map('strval', $ids))->get();

            if ($found->isEmpty()) {
                throw new RuntimeException('Tidak ada '.$label.' yang cocok dengan daftar ID itu.');
            }

            return $found;
        }

        if (($arguments['all'] ?? false) === true) {
            // Only non-blank values count as a filter. Otherwise a `where` of
            // {"status": null} would look scoped while narrowing nothing, and the
            // confirm rail below would be skipped for a full wipe.
            $where = array_filter(
                is_array($arguments['where'] ?? null) ? $arguments['where'] : [],
                fn ($value) => filled($value),
            );

            // Reject a filter this entity cannot express instead of ignoring it.
            // A silently dropped `where` is exactly how a caller deletes far more
            // than it asked for while believing it was scoped.
            $unsupported = array_diff(array_keys($where), $whereKeys);

            if ($unsupported !== []) {
                throw new RuntimeException(
                    'Filter `where` tidak dikenal untuk '.$label.': '.implode(', ', $unsupported)
                    .'. Yang didukung: '.($whereKeys === [] ? '(tidak ada)' : implode(', ', $whereKeys)).'.'
                );
            }

            if ($where === []) {
                $this->guardUnscopedAll($arguments, $label);
            }

            foreach ($where as $key => $value) {
                $query->where($key, match ($key) {
                    'status' => ItemStatus::normalize($value)->value,
                    'priority' => ItemPriority::normalize($value)->value,
                    default => (string) $value,
                });
            }

            return $query->get();
        }

        $id = $arguments[$singleKey] ?? null;

        if (blank($id)) {
            throw new RuntimeException(
                'Sebutkan '.$singleKey.', '.$pluralKey.', atau all: true untuk menentukan '.$label.' mana yang dimaksud.'
            );
        }

        return $query->whereKey($id)->get();
    }

    /**
     * Refuse an `all` that names nothing.
     *
     * WHY: `all: true` is the only selector that can touch rows the caller never
     * named -- a typo in an id list can only ever affect rows that were written
     * out by hand. Requiring a second flag for the unscoped case means a
     * whole-workspace wipe takes two deliberate decisions instead of one.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function guardUnscopedAll(array $arguments, string $label): void
    {
        if (($arguments['confirm'] ?? false) !== true) {
            throw new RuntimeException(
                'all: true tanpa `where` akan mengenai SELURUH '.$label.' di workspace. '
                .'Tambahkan pembatas di `where`, atau set confirm: true bila memang itu yang diminta.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return EloquentCollection<int, Category>
     */
    private function categoryTargets(array $arguments): EloquentCollection
    {
        $ids = $arguments['category_ids'] ?? null;

        if (is_array($ids) && $ids !== []) {
            $found = Category::query()->whereIn('id', array_map('strval', $ids))->get();

            if ($found->isEmpty()) {
                throw new RuntimeException('Tidak ada kategori yang cocok dengan daftar ID itu.');
            }

            return $found;
        }

        if (($arguments['all'] ?? false) === true) {
            $this->guardUnscopedAll($arguments, 'kategori');

            return Category::query()->orderBy('order_index')->get();
        }

        $category = $this->resolveCategoryRow($arguments);

        if (! $category) {
            throw new RuntimeException(
                'Sebutkan category_id, name, category_ids, atau all: true untuk menentukan kategori mana yang dimaksud.'
            );
        }

        return new EloquentCollection([$category]);
    }

    /**
     * Look a category up for the category tools themselves.
     *
     * WHY this is not resolveCategoryId(): that one is also reached from the
     * project tools, where `name` means the PROJECT name. Accepting `name` there
     * would silently file a project into whatever category happened to share its
     * name. The category tools are the only place where `name` denotes a
     * category.
     *
     * It also never creates: resolveCategoryId()'s firstOrCreate would turn
     * "delete category Foo" into "create Foo, then delete it".
     *
     * @param  array<string, mixed>  $arguments
     */
    private function resolveCategoryRow(array $arguments): ?Category
    {
        foreach (['category_id', 'name', 'category_name'] as $key) {
            if (blank($arguments[$key] ?? null)) {
                continue;
            }

            $value = trim((string) $arguments[$key]);

            $found = $key === 'category_id'
                ? Category::whereKey($value)->first()
                : Category::where('slug', Str::slug($value))->first() ?? Category::where('name', $value)->first();

            if ($found) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $arguments */
    private function resolveCategory(array $arguments): Category
    {
        $id = $this->resolveCategoryId($arguments);

        if ($id === null) {
            throw new RuntimeException('Kategori tidak ditemukan. Sebutkan category_id atau nama kategori.');
        }

        return Category::findOrFail($id);
    }

    /** @param array<string, mixed> $arguments */
    private function resolveProject(array $arguments): Project
    {
        $id = $arguments['project_id'] ?? null;

        if (filled($id)) {
            return Project::findOrFail($id);
        }

        // Fall back to the most recently touched project, matching what the
        // palette does when opened from a project screen.
        $project = Project::query()->latest('updated_at')->first();

        if (! $project) {
            throw new RuntimeException('Belum ada project di workspace.');
        }

        return $project;
    }

    /** @param array<string, mixed> $arguments */
    private function requireString(array $arguments, string $key): string
    {
        $value = trim((string) ($arguments[$key] ?? ''));

        if ($value === '') {
            throw new RuntimeException($key.' wajib diisi.');
        }

        return $value;
    }

    /**
     * @return array{message: string}
     */
    private function aiFailure(Throwable $e): array
    {
        Log::error('AI orchestration failed.', ['exception' => $e->getMessage()]);

        return ['status' => 'error', 'message' => 'Gagal memanggil DeepSeek: '.$e->getMessage()];
    }

    /** @return array<string, mixed> */
    private function categoryPayload(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'color' => $category->color,
            'order_index' => $category->order_index,
        ];
    }

    /** @return array<string, mixed> */
    private function projectPayload(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'category_id' => $project->category_id,
            'category' => $project->relationLoaded('category') ? $project->category?->name : null,
            'my_role' => $project->my_role,
            'status' => $project->status->value,
            'order_index' => $project->order_index,
        ];
    }

    /** @return array<string, mixed> */
    private function modulePayload(ProjectModule $module): array
    {
        return [
            'id' => $module->id,
            'project_id' => $module->project_id,
            'title' => $module->title,
            'status' => $module->status->value,
            'priority' => $module->priority->value,
            'order_index' => $module->order_index,
            'due_date' => $module->due_date?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function taskPayload(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'notes' => $task->notes,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'due_date' => $task->due_date?->toDateString(),
            'order_index' => $task->order_index,
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $clean = trim((string) $value);

        return $clean === '' ? null : $clean;
    }

    private function validDate(mixed $value): ?string
    {
        $clean = $this->nullable($value);

        if ($clean === null) {
            return null;
        }

        $timestamp = strtotime($clean);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
