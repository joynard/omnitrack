<?php

namespace App\Models;

use App\Enums\ItemPriority;
use App\Enums\ItemStatus;
use App\Models\Concerns\ReordersByIds;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, HasUlids, ReordersByIds;

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
}
