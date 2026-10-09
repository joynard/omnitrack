<?php

namespace Database\Factories;

use App\Enums\ItemPriority;
use App\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(5),
            'notes' => fake()->optional()->sentence(),
            'status' => ItemStatus::PENDING,
            'priority' => fake()->randomElement(ItemPriority::cases()),
            'due_date' => null,
        ];
    }
}
