<?php

namespace App\Models;

use App\Enums\ItemPriority;
use App\Enums\ItemStatus;
use App\Models\Concerns\ReordersByIds;
use Database\Factories\ProjectModuleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectModule extends Model
{
    /** @use HasFactory<ProjectModuleFactory> */
    use HasFactory, HasUlids, ReordersByIds;

    protected $fillable = [
        'project_id',
        'title',
        'description',
        'status',
        'priority',
        'due_date',
        'order_index',
    ];

    protected function casts(): array
    {
        return [
            'status' => ItemStatus::class,
            'priority' => ItemPriority::class,
            'due_date' => 'date',
            'order_index' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
