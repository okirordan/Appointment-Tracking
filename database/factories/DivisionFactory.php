<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

class DivisionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'name' => fake()->unique()->words(2, true).' Division',
            'code' => strtoupper(fake()->unique()->bothify('D###')),
            'active' => true,
        ];
    }
}
