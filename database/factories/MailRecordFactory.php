<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\MailRecord> */
class MailRecordFactory extends Factory
{
    public function definition(): array
    {
        $direction = fake()->randomElement(['incoming', 'outgoing']);

        return [
            'direction' => $direction,
            'register_number' => strtoupper(substr($direction, 0, 1)).'M-'.now()->year.'-'.fake()->unique()->numberBetween(10000, 99999),
            'sender_name' => fake()->name(),
            'recipient_name' => 'Permanent Secretary',
            'subject' => fake()->sentence(6),
            'details' => fake()->paragraph(),
            'received_date' => $direction === 'incoming' ? today() : null,
            'sent_date' => $direction === 'outgoing' ? today() : null,
            'confidentiality' => 'normal',
            'captured_by_user_id' => User::factory(),
        ];
    }

    public function incoming(): static
    {
        return $this->state(fn () => ['direction' => 'incoming', 'received_date' => today(), 'sent_date' => null]);
    }

    public function outgoing(): static
    {
        return $this->state(fn () => ['direction' => 'outgoing', 'received_date' => null, 'sent_date' => today()]);
    }
}
