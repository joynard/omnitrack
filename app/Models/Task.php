<?php

namespace App\Models;

use App\Enums\ItemPriority;
use App\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'title',
        'notes',
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
