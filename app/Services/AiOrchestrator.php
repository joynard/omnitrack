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
            'delete_category' => $this->toolDeleteCategory($arguments),
            'create_project' => $this->toolCreateProject($arguments),
            'update_project' => $this->toolUpdateProject($arguments),
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

        $category = Category::firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name, 'order_index' => (int) (Category::max('order_index') ?? -1) + 1]
        );

        return [
            'message' => 'Kategori "'.$category->name.'" '.($category->wasRecentlyCreated ? 'dibuat' : 'sudah ada').'.',
            'data' => ['category' => $this->categoryPayload($category)],
        ];
    }

    private function toolUpdateCategory(array $arguments): array
    {
        $category = $this->resolveCategory($arguments);

        if (filled($arguments['new_name'] ?? null)) {
            $newName = trim((string) $arguments['new_name']);
            $category->name = $newName;
            $category->slug = Str::slug($newName);
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

    private function toolDeleteCategory(array $arguments): array
    {
        $category = $this->resolveCategory($arguments);
        $name = $category->name;
        $affected = $category->projects()->count();

        // nullOnDelete on the FK keeps the projects; they just lose the label.
        $category->delete();

        return [
            'message' => 'Kategori "'.$name.'" dihapus.'
                .($affected > 0 ? ' '.$affected.' project menjadi tanpa kategori.' : ''),
            'data' => ['deleted_category' => $name, 'unfiled_projects' => $affected],
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
        $project = $this->resolveProject($arguments);
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

        $project->fill($attributes)->save();

        return [
            'message' => 'Project "'.$project->name.'" diperbarui.',
            'data' => ['project' => $this->projectPayload($project->fresh('category'))],
        ];
    }

    private function toolDeleteProject(array $arguments): array
    {
        $project = $this->resolveProject($arguments);
        $name = $project->name;
        $modules = $project->modules()->count();

        // Modules go with it via cascadeOnDelete on the FK.
        $project->delete();

        return [
            'message' => 'Project "'.$name.'" dihapus'
                .($modules > 0 ? ' beserta '.$modules.' modulnya' : '').'.',
            'data' => ['deleted_project' => $name],
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
        $module = $this->resolveModule($arguments);
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
            $attributes['due_date'] = $this->validDate($arguments['due_date']);
        }

        $module->fill($attributes)->save();

        return [
            'message' => 'Modul "'.$module->title.'" diperbarui.',
            'data' => ['module' => $this->modulePayload($module->fresh())],
        ];
    }

    private function toolDeleteModule(array $arguments): array
    {
        $module = $this->resolveModule($arguments);
        $title = $module->title;
        $module->delete();

        return [
            'message' => 'Modul "'.$title.'" dihapus.',
            'data' => ['deleted_module' => $title],
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
        $task = $this->resolveTask($arguments);
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
            $attributes['due_date'] = $this->validDate($arguments['due_date']);
        }

        $task->fill($attributes)->save();

        return [
            'message' => 'Task "'.$task->title.'" diperbarui.',
            'data' => ['task' => $this->taskPayload($task->fresh())],
        ];
    }

    private function toolDeleteTask(array $arguments): array
    {
        $task = $this->resolveTask($arguments);
        $title = $task->title;
        $task->delete();

        return [
            'message' => 'Task "'.$title.'" dihapus.',
            'data' => ['deleted_task' => $title],
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
                ['role' => 'system', 'content' => 'Kamu asisten manajemen proyek. Jawab singkat, padat, maksimal 5 poin, tanpa basa-basi.'],
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

        return [
            'stats' => [
                'categories_total' => $categories->count(),
                'projects_total' => $projects->count(),
                'projects_done' => $projects->filter(fn (Project $p) => $p->status === ItemStatus::DONE)->count(),
                'modules_total' => $projects->sum(fn (Project $p) => $p->modules->count()),
                'modules_pending' => $projects->sum(fn (Project $p) => $p->modules->where('status', ItemStatus::PENDING)->count()),
                'tasks_total' => $tasks->count(),
                'tasks_done' => $tasks->filter(fn (Task $t) => $t->status === ItemStatus::DONE)->count(),
                'tasks_open' => $tasks->filter(fn (Task $t) => $t->status !== ItemStatus::DONE)->count(),
            ],
            'categories' => $categories->map(fn (Category $c) => $this->categoryPayload($c))->all(),
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
        Kamu adalah agen pengelola workspace personal bernama Omnitrack.

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
    private function resolveModule(array $arguments): ProjectModule
    {
        $id = $arguments['module_id'] ?? null;

        if (blank($id)) {
            throw new RuntimeException('module_id wajib diisi.');
        }

        return ProjectModule::findOrFail($id);
    }

    /** @param array<string, mixed> $arguments */
    private function resolveTask(array $arguments): Task
    {
        $id = $arguments['task_id'] ?? null;

        if (blank($id)) {
            throw new RuntimeException('task_id wajib diisi.');
        }

        return Task::findOrFail($id);
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
