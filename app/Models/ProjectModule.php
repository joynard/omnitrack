<?php

namespace App\Models;

use App\Enums\ItemPriority;
use App\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectModule extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectModuleFactory> */
    use HasFactory, HasUlids;

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

        // upsert() touches only the listed columns; the unique key is the ULID.
        static::upsert($rows, ['id'], ['order_index', 'updated_at']);
    }
}
