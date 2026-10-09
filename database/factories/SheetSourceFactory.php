<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\SheetSource>
 */
class SheetSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Workspace Master Tracker',
            'spreadsheet_id' => '1lgivxc5ZnctCyX9swahxWjZMiq2tl7JSOEHvJ2Dxktw',
            'sheet_range' => 'Sheet1!A2:H',
            'is_active' => true,
            'last_synced_at' => null,
        ];
    }
}
