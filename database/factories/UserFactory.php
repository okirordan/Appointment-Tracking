<?php

namespace Database\Factories;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'password' => static::$password ??= 'Password@123',
            'full_name' => fake()->name(),
            'title' => fake()->jobTitle(),
            'role' => Role::Officer->value,
            'department_id' => null,
            'active' => true,
            'locked' => false,
            'force_password_change' => false,
            'password_changed_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function role(Role $role): static
    {
        return $this->state(fn () => ['role' => $role->value]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    public function locked(): static
    {
        return $this->state(fn () => ['locked' => true]);
    }
}
