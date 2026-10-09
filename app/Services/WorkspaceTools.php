<?php

namespace App\Services;

/**
 * OpenAI-style tool definitions for the AI command palette.
 *
 * These are what give the palette full control over categories, projects,
 * modules, their ordering, and tasks. Each name here must have a matching
 * handler in AiOrchestrator::executeTool().
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
            self::deleteCategory(),
            self::createProject(),
            self::updateProject(),
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

    // ------------------------------------------------------------ categories

    private static function createCategory(): array
    {
        return self::function(
            'create_category',
            'Buat kategori baru untuk mengelompokkan project, misalnya "Kuliah", "Kantor", "Pribadi".',
            ['name' => ['type' => 'string', 'description' => 'Nama kategori.']],
            ['name']
        );
    }

    private static function updateCategory(): array
    {
        return self::function(
            'update_category',
            'Ubah nama kategori yang sudah ada. Cari berdasarkan category_id atau nama lama.',
            [
                'category_id' => self::id('ID kategori.'),
                'name' => ['type' => 'string', 'description' => 'Nama kategori saat ini (alternatif category_id).'],
                'new_name' => ['type' => 'string', 'description' => 'Nama baru.'],
                'order_index' => ['type' => 'integer', 'description' => 'Posisi urutan kategori.'],
            ]
        );
    }

    private static function deleteCategory(): array
    {
        return self::function(
            'delete_category',
            'Hapus kategori. Project di dalamnya TIDAK terhapus, hanya menjadi tanpa kategori.',
            [
                'category_id' => self::id('ID kategori.'),
                'name' => ['type' => 'string', 'description' => 'Nama kategori (alternatif category_id).'],
            ]
        );
    }

    // -------------------------------------------------------------- projects

    private static function createProject(): array
    {
        return self::function(
            'create_project',
            'Buat project baru, boleh langsung dengan kategori.',
            [
                'name' => ['type' => 'string', 'description' => 'Nama project.'],
                'category_id' => self::id('ID kategori.'),
                'category_name' => ['type' => 'string', 'description' => 'Nama kategori; dibuat otomatis bila belum ada.'],
                'my_role' => ['type' => 'string'],
                'department' => ['type' => 'string'],
                'target_user' => ['type' => 'string'],
                'project_owner' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => self::STATUS],
                'doc_link' => ['type' => 'string'],
            ],
            ['name']
        );
    }

    private static function updateProject(): array
    {
        return self::function(
            'update_project',
            'Ubah atribut project: nama, status, kategori, deskripsi, dan lainnya.',
            [
                'project_id' => self::id('ID project.'),
                'name' => ['type' => 'string'],
                'category_id' => self::id('Pindahkan ke kategori ini.'),
                'category_name' => ['type' => 'string', 'description' => 'Pindahkan ke kategori bernama ini.'],
                'my_role' => ['type' => 'string'],
                'department' => ['type' => 'string'],
                'target_user' => ['type' => 'string'],
                'project_owner' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => self::STATUS],
                'doc_link' => ['type' => 'string'],
                'order_index' => ['type' => 'integer', 'description' => 'Posisi urutan project.'],
            ],
            ['project_id']
        );
    }

    private static function deleteProject(): array
    {
        return self::function(
            'delete_project',
            'Hapus project beserta seluruh modulnya.',
            ['project_id' => self::id('ID project.')],
            ['project_id']
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
                            'status' => ['type' => 'string', 'enum' => self::STATUS],
                            'priority' => ['type' => 'string', 'enum' => self::PRIORITY],
                            'due_date' => ['type' => 'string', 'description' => 'Format YYYY-MM-DD.'],
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
            'Ubah satu modul: judul, status, prioritas, deskripsi, atau tenggat.',
            [
                'module_id' => self::id('ID modul.'),
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => self::STATUS],
                'priority' => ['type' => 'string', 'enum' => self::PRIORITY],
                'due_date' => ['type' => 'string', 'description' => 'Format YYYY-MM-DD.'],
            ],
            ['module_id']
        );
    }

    private static function deleteModule(): array
    {
        return self::function(
            'delete_module',
            'Hapus satu modul.',
            ['module_id' => self::id('ID modul.')],
            ['module_id']
        );
    }

    private static function reorderModules(): array
    {
        return self::function(
            'reorder_modules',
            'Atur ulang urutan modul dalam satu project. Sebutkan SEMUA module_id milik project itu sesuai urutan baru.',
            [
                'project_id' => self::id('ID project.'),
                'module_ids' => [
                    'type' => 'array',
                    'description' => 'ID modul dalam urutan baru, dari atas ke bawah.',
                    'items' => ['type' => 'string'],
                ],
            ],
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
                            'status' => ['type' => 'string', 'enum' => self::STATUS],
                            'priority' => ['type' => 'string', 'enum' => self::PRIORITY],
                            'due_date' => ['type' => 'string', 'description' => 'Format YYYY-MM-DD.'],
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
            'Ubah satu task: judul, catatan, status, prioritas, atau tenggat.',
            [
                'task_id' => self::id('ID task.'),
                'title' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => self::STATUS],
                'priority' => ['type' => 'string', 'enum' => self::PRIORITY],
                'due_date' => ['type' => 'string', 'description' => 'Format YYYY-MM-DD.'],
            ],
            ['task_id']
        );
    }

    private static function deleteTask(): array
    {
        return self::function(
            'delete_task',
            'Hapus satu task.',
            ['task_id' => self::id('ID task.')],
            ['task_id']
        );
    }

    private static function reorderTasks(): array
    {
        return self::function(
            'reorder_tasks',
            'Atur ulang urutan seluruh daftar task. Sebutkan SEMUA task_id sesuai urutan baru.',
            [
                'task_ids' => [
                    'type' => 'array',
                    'description' => 'ID task dalam urutan baru, dari atas ke bawah.',
                    'items' => ['type' => 'string'],
                ],
            ],
            ['task_ids']
        );
    }
}
