<?php

namespace Database\Factories;

use App\Enums\AssignmentLevel;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'TST-'.now()->year.'-'.fake()->unique()->numberBetween(100, 999),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'assignment_level' => AssignmentLevel::Department->value,
            'assigned_by_user_id' => User::factory(),
            'assigned_to_user_id' => User::factory(),
            'assigned_to_name_snapshot' => fake()->name(),
            'department_id' => null,
            'priority' => Priority::Medium->value,
            'due_date' => now()->addDays(10)->toDateString(),
            'original_due_date' => now()->addDays(10)->toDateString(),
            'workflow_status' => TaskStatus::Assigned->value,
            'progress_percent' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (\App\Models\Task $task) {
            $task->update(['assigned_to_name_snapshot' => $task->assignedTo?->full_name ?? $task->assigned_to_name_snapshot]);
        });
    }

    public function level(AssignmentLevel $level): static
    {
        return $this->state(fn () => ['assignment_level' => $level->value]);
    }

    public function status(TaskStatus $status): static
    {
        return $this->state(fn () => [
            'workflow_status' => $status->value,
            'progress_percent' => $status->suggestedProgress(),
            'completed_at' => $status === TaskStatus::Completed ? now() : null,
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'due_date' => now()->subDays(5)->toDateString(),
            'original_due_date' => now()->subDays(5)->toDateString(),
            'workflow_status' => TaskStatus::InProgress->value,
            'progress_percent' => 25,
        ]);
    }
}
