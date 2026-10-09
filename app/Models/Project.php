<?php

namespace App\Models;

use App\Enums\ItemStatus;
use App\Models\Concerns\ReordersByIds;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUlids, ReordersByIds;

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
}
