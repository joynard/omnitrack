<?php

namespace App\Models;

use App\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'category_id',
        'sheet_source_id',
        'sheet_row_index',
        'name',
        'my_role',
        'department',
        'target_user',
        'project_owner',
        'description',
        'status',
        'doc_link',
        'row_hash',
        'order_index',
    ];

    protected function casts(): array
    {
        return [
            'sheet_row_index' => 'integer',
            'order_index' => 'integer',
            'status' => ItemStatus::class,
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<SheetSource, $this> */
    public function sheetSource(): BelongsTo
    {
        return $this->belongsTo(SheetSource::class);
    }

    /** @return HasMany<ProjectModule, $this> */
    public function modules(): HasMany
    {
        return $this->hasMany(ProjectModule::class)->orderBy('order_index');
    }

    /** Completion ratio of child modules, used by the dashboard summary. */
    public function progress(): int
    {
        $total = $this->modules->count();

        if ($total === 0) {
            return $this->status === ItemStatus::DONE ? 100 : 0;
        }

        $done = $this->modules->where('status', ItemStatus::DONE)->count();

        return (int) round(($done / $total) * 100);
    }

    /**
     * Apply a complete id => position map in one statement.
     *
     * Shared by every reorder path (AI, board UI, harness API) so ordering has
     * exactly one implementation, and a single statement avoids N queries.
     *
     * @param  array<string, int>  $positions
     */
    public static function reorderByIds(array $positions): void
    {
        if ($positions === []) {
            return;
        }

        $now = now();

        $rows = [];
        foreach ($positions as $id => $position) {
            $rows[] = [
                'id' => (string) $id,
                'order_index' => (int) $position,
                'updated_at' => $now,
            ];
        }

        static::upsert($rows, ['id'], ['order_index', 'updated_at']);
    }
}
