<?php

namespace Database\Factories;

use App\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sheet_source_id' => null,
            'sheet_row_index' => null,
            'name' => fake()->unique()->catchPhrase(),
            'my_role' => fake()->jobTitle(),
            'department' => fake()->randomElement(['Operations', 'Product', 'Finance']),
            'target_user' => fake()->name(),
            'project_owner' => fake()->name(),
            'description' => fake()->sentence(),
            'status' => ItemStatus::PENDING,
            'doc_link' => null,
            'row_hash' => null,
        ];
    }
}
