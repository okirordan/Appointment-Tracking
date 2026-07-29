<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class WorkstreamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(['project', 'programme', 'initiative', 'subject']),
            'name' => fake()->unique()->sentence(3),
            'code' => strtoupper(fake()->unique()->bothify('WS-###')),
            'active' => true,
        ];
    }
}
