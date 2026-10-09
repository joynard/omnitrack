<?php

namespace Database\Factories;

use App\Enums\AiActionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\AiPromptTemplate>
 */
class AiPromptTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'prompt_payload' => fake()->sentence(8),
            'action_type' => fake()->randomElement(AiActionType::cases()),
        ];
    }
}
