<?php

namespace Database\Factories;

use App\Enums\ItemPriority;
use App\Enums\ItemStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\ProjectModule>
 */
class ProjectModuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'status' => ItemStatus::PENDING,
            'priority' => fake()->randomElement(ItemPriority::cases()),
            'due_date' => null,
            'order_index' => 0,
        ];
    }
}
