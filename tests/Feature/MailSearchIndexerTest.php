<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Services\MailSearchIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailSearchIndexerTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_document_contains_related_names_and_every_historical_task_note(): void
    {
        $viewer = User::factory()->role(Role::Ps)->create();
        $assignee = User::factory()->role(Role::Officer)->create(['full_name' => 'Sam Kato']);
        $department = Department::factory()->create(['name' => 'Education Planning', 'code' => 'EDP']);
        $task = Task::factory()->create([
            'assigned_to_user_id' => $assignee->id,
            'assigned_to_name_snapshot' => 'Sam Kato',
        ]);
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $viewer->id,
            'subject' => 'Budget review',
            'recipient_name' => 'PS/ES',
            'department_id' => $department->id,
            'task_id' => $task->id,
        ]);
        foreach (['First historic instruction', 'Second historic instruction'] as $note) {
            TaskHistory::create([
                'task_id' => $task->id,
                'action_type' => 'Annotated',
                'note' => $note,
                'performed_by_user_id' => $viewer->id,
                'performed_by_name_snapshot' => $viewer->full_name,
                'performed_by_role' => $viewer->role->value,
                'created_at' => now(),
            ]);
        }

        app(MailSearchIndexer::class)->rebuild($mail);
        $text = $mail->fresh()->search_text;

        foreach (['Budget review', 'Education Planning', 'EDP', 'Sam Kato', 'First historic instruction', 'Second historic instruction'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }

        $department->update(['name' => 'National Education Planning']);
        app(MailSearchIndexer::class)->rebuildMany([$mail->id]);
        $this->assertStringContainsString('National Education Planning', $mail->fresh()->search_text);
    }
}
