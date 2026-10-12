<?php

namespace App\Support;

use App\Enums\ItemStatus;

/**
 * The project field contract, declared once.
 *
 * WHY this class exists: the same list of project fields used to be written four
 * times -- as validation rules in HarnessController (twice), as validation rules
 * in BoardController, and as JSON-schema properties for the palette tools in
 * WorkspaceTools -- with a fifth copy of the "copy these onto the model" subset in
 * AiOrchestrator::projectAttributes(). Adding a field to one and forgetting
 * another is invisible until a caller sends it and it is silently dropped.
 *
 * The three shapes it serves are deliberately different, so this is not one array
 * reused everywhere: `rules()` is what each HTTP surface accepts, `mapped()` is
 * the subset that lands on the model, and `schema()` is what the LLM sees. What
 * they now share is the field NAMES, which is the part that drifted.
 */
final class ProjectFields
{
    /**
     * Validation for the shared project fields.
     *
     * @var array<string, list<string>>
     */
    private const RULES = [
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
    ];

    /** Fields AiOrchestrator copies straight onto the model. */
    public const MAPPED = ['my_role', 'department', 'target_user', 'project_owner', 'description', 'doc_link'];

    /**
     * POST /api/harness/projects: create, or update when `id` is supplied.
     *
     * @return array<string, list<string>>
     */
    public static function upsertRules(): array
    {
        $rules = ['id' => ['nullable', 'string', 'exists:projects,id']] + self::RULES;

        // Only the create path requires a name; an update driven by `id` may omit it.
        $rules['name'] = ['required_without:id', 'nullable', 'string', 'max:255'];

        return $rules;
    }

    /**
     * POST /api/harness/projects/{project} and the board's update endpoint.
     *
     * WHY the flag: the board files a project under a category by id and has no
     * "create the category by name" path, so it refuses `category_name` -- that is
     * the one place where the two surfaces genuinely differ.
     *
     * @return array<string, list<string>>
     */
    public static function updateRules(bool $withCategoryName = true): array
    {
        $rules = self::RULES;

        if (! $withCategoryName) {
            unset($rules['category_name']);
        }

        return $rules;
    }

    /**
     * JSON-schema properties for the palette tools, in create order.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(): array
    {
        return [
            'category_id' => ['type' => 'string', 'description' => 'ID kategori.'],
            'category_name' => ['type' => 'string', 'description' => 'Nama kategori; dibuat otomatis bila belum ada.'],
            'my_role' => ['type' => 'string'],
            'department' => ['type' => 'string'],
            'target_user' => ['type' => 'string'],
            'project_owner' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'status' => ['type' => 'string', 'enum' => array_column(ItemStatus::cases(), 'value')],
            'doc_link' => ['type' => 'string'],
            'order_index' => ['type' => 'integer', 'description' => 'Posisi urutan project; default di paling bawah.'],
        ];
    }
}
