<?php

namespace App\Services;

use App\Support\ProjectFields;

/**
 * OpenAI-style tool definitions for the AI command palette.
 *
 * These are what give the palette full control over categories, projects,
 * modules, their ordering, and tasks. Each name here must have a matching
 * handler in AiOrchestrator::executeTool().
 *
 * BULK TARGETING
 * --------------
 * Every mutating tool except the create/reorder ones accepts the same four
 * keys, and AiOrchestrator resolves them in one fixed order:
 *
 *   1. `<entity>_ids`  an explicit list -- wins over everything else
 *   2. `all: true`     every row matching the optional `where` filter
 *   3. `<entity>_id`   a single row (categories also accept `name`)
 *
 * `all` is a separate boolean rather than "an omitted id means everything",
 * because the dangerous case has to be something the model says out loud: a
 * typo in an id list can only ever affect rows that were named. An `all: true`
 * with no `where` at all is the one path that can touch rows nobody named, so
 * it additionally requires `confirm: true` -- two deliberate decisions before a
 * whole-workspace wipe.
 *
 * `where` advertises ONLY the filters its entity can actually express, because
 * AiOrchestrator rejects an unsupported key rather than ignoring it, and a
 * schema that offers a filter the query then drops is worse than no filter at
 * all.
 */
class WorkspaceTools
{
    /** Statuses accepted from the model, normalised server-side afterwards. */
    private const STATUS = ['pending', 'in_progress', 'done'];

    private const PRIORITY = ['low', 'medium', 'high'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::createCategory(),
            self::updateCategory(),
            self::reorderCategories(),
            self::deleteCategory(),
            self::createProject(),
            self::updateProject(),
            self::reorderProjects(),
            self::deleteProject(),
            self::createModule(),
            self::updateModule(),
            self::deleteModule(),
            self::reorderModules(),
            self::createTask(),
            self::updateTask(),
            self::deleteTask(),
            self::reorderTasks(),
        ];
    }

    /** Tool names, for validating a model response. */
    public static function names(): array
    {
        return array_map(fn (array $tool) => $tool['function']['name'], self::all());
    }

    // ---------------------------------------------------------------- schema

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function function(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function id(string $description): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    /**
     * The target selector every bulk-capable tool shares.
     *
     * @param  array<string, mixed>  $whereProperties  exactly the filters this entity supports
     * @return array<string, mixed>
     */
    private static function targets(string $single, string $plural, string $what, array $whereProperties = []): array
    {
        return [
            $plural => [
                'type' => 'array',
                'description' => "Daftar ID $what. Bila diisi, `all` dan $single diabaikan.",
                'items' => ['type' => 'string'],
            ],
            'all' => [
                'type' => 'boolean',
                'description' => "true = kenai SEMUA $what yang cocok dengan `where`, bukan hanya yang disebut namanya.",
            ],
            'where' => [
                'type' => 'object',
                'description' => $whereProperties === []
                    ? "Tidak ada filter untuk $what, jadi `all: true` selalu berarti seluruh workspace dan selalu mewajibkan `confirm: true`."
                    : 'Pembatas untuk `all: true`. Kosong sama sekali = seluruh workspace, dan itu mewajibkan `confirm: true`.',
                // An empty PHP array encodes as JSON `[]`, and JSON Schema requires
                // `properties` to be an object. `{}` is what expresses "this entity
                // accepts no filter at all".
                'properties' => $whereProperties === [] ? new \stdClass : $whereProperties,
                'additionalProperties' => false,
            ],
            'confirm' => [
                'type' => 'boolean',
                'description' => "WAJIB true bila `all: true` tanpa `where` sama sekali, karena itu mengenai SELURUH $what di workspace. Jangan pernah set true tanpa instruksi eksplisit dari user.",
            ],
            $single => self::id("ID $what tunggal. Dipakai bila $plural dan `all` tidak diisi."),
        ];
    }

    /** @return array<string, mixed> */
    private static function statusField(): array
    {
        return ['type' => 'string', 'enum' => self::STATUS];
    }

    /** @return array<string, mixed> */
    private static function priorityField(): array
    {
        return ['type' => 'string', 'enum' => self::PRIORITY];
    }

    /** @return array<string, mixed> */
    private static function dueDateField(): array
    {
        return [
            'type' => 'string',
            'description' => 'Tenggat. Format YYYY-MM-DD, atau ekspresi relatif yang dimengerti strtotime (mis. "+7 days", "next monday").',
        ];
    }

    /** @return array<string, mixed> */
    private static function dueDateShiftField(): array
    {
        return [
            'type' => 'integer',
            'description' => 'Geser tenggat MASING-MASING baris sebanyak N hari (boleh negatif). Baris yang belum punya tenggat dibiarkan. Tidak boleh dipakai bersama `due_date`.',
        ];
    }

    /** @return array<string, mixed> */
    private static function reorderIdsField(string $what, string $plural): array
    {
        return [
            $plural => [
                'type' => 'array',
                'description' => "ID $what dalam urutan baru, dari atas ke bawah.",
                'items' => ['type' => 'string'],
            ],
        ];
    }

    // ------------------------------------------------------------ categories

    private static function createCategory(): array
    {
        return self::function(
            'create_category',
            'Buat kategori baru untuk mengelompokkan project, misalnya "Kuliah", "Kantor", "Pribadi". Bila kategorinya sudah ada, warna/urutan yang diberikan akan diisikan.',
            [
                'name' => ['type' => 'string', 'description' => 'Nama kategori.'],
                'color' => ['type' => 'string', 'description' => 'Warna label, mis. "#7C3AED".'],
                'order_index' => ['type' => 'integer', 'description' => 'Posisi urutan kategori; default di paling bawah.'],
            ],
            ['name']
        );
    }

    private static function updateCategory(): array
    {
        return self::function(
            'update_category',
            'Ubah nama, warna, atau urutan satu kategori. Cari berdasarkan category_id atau nama lamanya di `name`.',
            [
                'category_id' => self::id('ID kategori.'),
                'name' => ['type' => 'string', 'description' => 'Nama kategori saat ini (alternatif category_id).'],
                'new_name' => ['type' => 'string', 'description' => 'Nama baru.'],
                'color' => ['type' => 'string', 'description' => 'Warna label baru.'],
                'order_index' => ['type' => 'integer', 'description' => 'Posisi urutan kategori.'],
            ]
        );
    }

    private static function reorderCategories(): array
    {
        return self::function(
            'reorder_categories',
            'Atur ulang urutan kategori. Sebutkan SEMUA category_id sesuai urutan baru, dari atas ke bawah.',
            self::reorderIdsField('kategori', 'category_ids'),
            ['category_ids']
        );
    }

    private static function deleteCategory(): array
    {
        return self::function(
            'delete_category',
            'Hapus satu atau banyak kategori sekaligus. Project di dalamnya TIDAK terhapus, hanya menjadi tanpa kategori. Pakai `all: true` untuk menghapus semua kategori.',
            array_merge(self::targets('category_id', 'category_ids', 'kategori'), [
                'name' => ['type' => 'string', 'description' => 'Nama kategori saat ini (alternatif category_id).'],
            ])
        );
    }

    // -------------------------------------------------------------- projects

    private static function createProject(): array
    {
        return self::function(
            'create_project',
            'Buat project baru, boleh langsung dengan kategori.',
            array_merge(
                ['name' => ['type' => 'string', 'description' => 'Nama project.']],
                ProjectFields::schema()
            ),
            ['name']
        );
    }

    private static function updateProject(): array
    {
        return self::function(
            'update_project',
            'Ubah satu atau banyak project sekaligus: nama, status, kategori, deskripsi, dan lainnya. Pakai `project_ids` untuk beberapa project, atau `all: true` untuk semua yang cocok dengan `where`.',
            array_merge(
                self::targets('project_id', 'project_ids', 'project', [
                    'status' => self::statusField(),
                    'category_id' => self::id('Pembatas: hanya project di kategori ini.'),
                ]),
                array_merge(
                    ProjectFields::schema(),
                    [
                        'name' => ['type' => 'string'],
                        // `category_id`/`category_name` keep their original meaning
                        // -- the category to MOVE to -- because the harness API and
                        // existing tests rely on it. The `where.category_id` above is
                        // the scope variant. `order_index` is reworded because on an
                        // update it moves a row, it does not append one.
                        'category_id' => self::id('Pindahkan ke kategori ini.'),
                        'category_name' => ['type' => 'string', 'description' => 'Pindahkan ke kategori bernama ini.'],
                        'order_index' => ['type' => 'integer', 'description' => 'Posisi urutan project.'],
                    ]
                )
            )
        );
    }

    private static function reorderProjects(): array
    {
        return self::function(
            'reorder_projects',
            'Atur ulang urutan project. Sebutkan SEMUA project_id sesuai urutan baru, dari atas ke bawah.',
            self::reorderIdsField('project', 'project_ids'),
            ['project_ids']
        );
    }

    private static function deleteProject(): array
    {
        return self::function(
            'delete_project',
            'Hapus satu atau banyak project sekaligus, beserta seluruh modulnya. Pakai `project_ids` untuk beberapa project, atau `all: true` (dengan `where`) untuk menghapus banyak sekaligus. TIDAK BISA DIBATALKAN.',
            self::targets('project_id', 'project_ids', 'project', [
                'status' => self::statusField(),
                'category_id' => self::id('Pembatas: hanya project di kategori ini.'),
            ])
        );
    }

    // --------------------------------------------------------------- modules

    private static function createModule(): array
    {
        return self::function(
            'create_module',
            'Tambah satu atau banyak modul/todo ke sebuah project sekaligus.',
            [
                'project_id' => self::id('ID project.'),
                'modules' => [
                    'type' => 'array',
                    'description' => 'Daftar modul yang ditambahkan, urut sesuai keinginan.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'status' => self::statusField(),
                            'priority' => self::priorityField(),
                            'due_date' => self::dueDateField(),
                        ],
                        'required' => ['title'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            ['project_id', 'modules']
        );
    }

    private static function updateModule(): array
    {
        return self::function(
            'update_module',
            'Ubah satu atau banyak modul sekaligus: judul, status, prioritas, deskripsi, tenggat, atau pindah project. Pakai `module_ids` untuk beberapa modul, atau `all: true` dengan `where` (mis. semua modul berstatus done di satu project).',
            array_merge(
                self::targets('module_id', 'module_ids', 'modul', [
                    'status' => self::statusField(),
                    'priority' => self::priorityField(),
                    'project_id' => self::id('Pembatas: hanya modul di project ini.'),
                ]),
                [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'status' => self::statusField(),
                    'priority' => self::priorityField(),
                    'due_date' => self::dueDateField(),
                    'due_date_shift_days' => self::dueDateShiftField(),
                    'move_to_project_id' => self::id('Pindahkan modul ke project ini; modul diletakkan di urutan paling bawah project tujuan.'),
                ]
            )
        );
    }

    private static function deleteModule(): array
    {
        return self::function(
            'delete_module',
            'Hapus satu atau banyak modul sekaligus. Pakai `module_ids` untuk beberapa modul, atau `all: true` dengan `where` (mis. hapus semua modul di satu project, atau semua modul berstatus done). TIDAK BISA DIBATALKAN.',
            self::targets('module_id', 'module_ids', 'modul', [
                'status' => self::statusField(),
                'priority' => self::priorityField(),
                'project_id' => self::id('Pembatas: hanya modul di project ini.'),
            ])
        );
    }

    private static function reorderModules(): array
    {
        return self::function(
            'reorder_modules',
            'Atur ulang urutan modul dalam satu project. Sebutkan SEMUA module_id milik project itu sesuai urutan baru.',
            array_merge(
                ['project_id' => self::id('ID project.')],
                self::reorderIdsField('modul', 'module_ids'),
            ),
            ['project_id', 'module_ids']
        );
    }

    // ----------------------------------------------------------------- tasks

    private static function createTask(): array
    {
        return self::function(
            'create_task',
            'Buat satu atau banyak task mandiri sekaligus.',
            [
                'tasks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'notes' => ['type' => 'string'],
                            'status' => self::statusField(),
                            'priority' => self::priorityField(),
                            'due_date' => self::dueDateField(),
                        ],
                        'required' => ['title'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            ['tasks']
        );
    }

    private static function updateTask(): array
    {
        return self::function(
            'update_task',
            'Ubah satu atau banyak task sekaligus: judul, catatan, status, prioritas, atau tenggat. Pakai `task_ids` untuk beberapa task, atau `all: true` dengan `where` (mis. tandai semua task berstatus pending jadi done).',
            array_merge(
                self::targets('task_id', 'task_ids', 'task', [
                    'status' => self::statusField(),
                    'priority' => self::priorityField(),
                ]),
                [
                    'title' => ['type' => 'string'],
                    'notes' => ['type' => 'string'],
                    'status' => self::statusField(),
                    'priority' => self::priorityField(),
                    'due_date' => self::dueDateField(),
                    'due_date_shift_days' => self::dueDateShiftField(),
                ]
            )
        );
    }

    private static function deleteTask(): array
    {
        return self::function(
            'delete_task',
            'Hapus satu atau banyak task sekaligus. Pakai `task_ids` untuk beberapa task, atau `all: true` dengan `where` (mis. hapus semua task berstatus done). TIDAK BISA DIBATALKAN.',
            self::targets('task_id', 'task_ids', 'task', [
                'status' => self::statusField(),
                'priority' => self::priorityField(),
            ])
        );
    }

    private static function reorderTasks(): array
    {
        return self::function(
            'reorder_tasks',
            'Atur ulang urutan seluruh daftar task. Sebutkan SEMUA task_id sesuai urutan baru.',
            self::reorderIdsField('task', 'task_ids'),
            ['task_ids']
        );
    }
}
