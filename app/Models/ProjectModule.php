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

    /**
     * Keep the parent project's status in step with its checklist.
     *
     * WHY here and not in the controllers: the board UI, the AI palette, the
     * Telegram bridge and the harness API all write modules, and this is the one
     * place every single-row write passes through.
     *
     * A module that MOVES between projects only fires `saved` on the destination,
     * and mass update/delete fire nothing at all -- those callers must also call
     * Project::syncStatusesFor() for the projects they left behind.
     */
    protected static function booted(): void
    {
        static::saved(fn (self $module) => $module->project?->syncStatusFromModules());
        static::deleted(fn (self $module) => $module->project?->syncStatusFromModules());
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
