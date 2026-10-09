<?php

namespace App\Models;

use App\Enums\AiActionType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiPromptTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\AiPromptTemplateFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'title',
        'prompt_payload',
        'action_type',
    ];

    protected function casts(): array
    {
        return [
            'action_type' => AiActionType::class,
        ];
    }
}
