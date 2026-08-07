<?php

namespace Tests\Feature\Tasks;

use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\EvidenceAttachment;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProgressWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function taskFor(User $officer): Task
    {
        return Task::factory()->create([
            'assigned_by_user_id' => User::factory()->role(Role::Commissioner)->create()->id,
            'assigned_to_user_id' => $officer->id,
        ]);
    }

    public function test_assignee_updates_progress_with_note()
    {
        $officer = User::factory()->role(Role::Officer)->create();
        $task = $this->taskFor($officer);

        $this->actingAs($officer)->post("/tasks/{$task->id}/progress", [
            'status' => 'in_progress',
            'progress' => 25,
            'note' => 'Work has commenced.',
        ])->assertRedirect(route('tasks.show', $task, absolute: false));

        $task->refresh();
        $this->assertSame(TaskStatus::InProgress, $task->workflow_status);
        $this->assertSame(25, $task->progress_percent);
        $this->assertDatabaseHas('task_histories', ['task_id' => $task->id, 'note' => 'Work has commenced.']);

        // PROG-007: the assigning supervisor is notified.
        $this->assertSame(1, Notification::where('user_id', $task->assigned_by_user_id)->count());
    }

    public function test_note_is_mandatory()
    {
        $officer = User::factory()->role(Role::Officer)->create();
        $task = $this->taskFor($officer);

        $this->actingAs($officer)->post("/tasks/{$task->id}/progress", [
            'status' => 'in_progress',
            'progress' => 25,
            'note' => '',
        ])->assertSessionHasErrors('note');
    }

    public function test_completion_requires_evidence()
    {
        $officer = User::factory()->role(Role::Officer)->create();
        $task = $this->taskFor($officer);

        $this->actingAs($officer)->post("/tasks/{$task->id}/progress", [
            'status' => 'completed',
            'progress' => 100,
            'note' => 'Done.',
        ])->assertSessionHasErrors('evidence');

        $this->assertSame(TaskStatus::Assigned, $task->refresh()->workflow_status);
    }

    public function test_completion_with_evidence_succeeds_and_stores_file_privately()
    {
        Storage::fake('evidence');
        $officer = User::factory()->role(Role::Officer)->create();
        $task = $this->taskFor($officer);

        $this->actingAs($officer)->post("/tasks/{$task->id}/progress", [
            'status' => 'completed',
            'progress' => 100,
            'note' => 'Report attached.',
            'evidence' => [UploadedFile::fake()->create('report.pdf', 120, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->workflow_status);
        $this->assertNotNull($task->completed_at);

        $attachment = $task->evidence()->firstOrFail();
        $this->assertSame('report.pdf', $attachment->original_filename);
        Storage::disk('evidence')->assertExists($attachment->storage_key);
    }

    public function test_progress_update_accepts_images_and_video_evidence(): void
    {
        Storage::fake('evidence');
        $officer = User::factory()->role(Role::Officer)->create();
        $task = $this->taskFor($officer);

        $this->actingAs($officer)->post(route('tasks.progress.store', $task), [
            'status' => 'in_progress',
            'progress' => 25,
            'note' => 'Photo and field video attached.',
            'evidence' => [
                UploadedFile::fake()->create('site-photo.jpg', 100, 'image/jpeg'),
                UploadedFile::fake()->create('site-visit.mp4', 200, 'video/mp4'),
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $task->evidence()->count());
        $this->assertSame(
            ['image/jpeg', 'video/mp4'],
            $task->evidence()->orderBy('id')->pluck('mime_type')->all(),
        );
    }

    public function test_evidence_link_can_satisfy_completion_requirement(): void
    {
        $officer = User::factory()->role(Role::Officer)->create();
        $task = $this->taskFor($officer);
        $url = 'https://example.org/evidence/final-report';

        $this->actingAs($officer)->post(route('tasks.progress.store', $task), [
            'status' => 'completed',
            'progress' => 100,
            'note' => 'Final evidence is available online.',
            'evidence_links' => [$url],
        ])->assertSessionHasNoErrors();

        $attachment = $task->evidence()->firstOrFail();
        $this->assertSame('link', $attachment->source_type);
        $this->assertSame($url, $attachment->external_url);
        $this->assertSame(TaskStatus::Completed, $task->refresh()->workflow_status);

        $this->actingAs($officer)->get(route('evidence.download', $attachment))->assertRedirect($url);
    }

    public function test_authorized_user_can_preview_pdf_inline_but_unrelated_user_cannot(): void
    {
        Storage::fake('evidence');
        $officer = User::factory()->role(Role::Officer)->create();
        $other = User::factory()->role(Role::Officer)->create();
        $task = $this->taskFor($officer);

        $this->actingAs($officer)->post(route('tasks.progress.store', $task), [
            'status' => 'in_progress',
            'progress' => 25,
            'note' => 'Previewable report attached.',
            'evidence' => [UploadedFile::fake()->create('report.pdf', 20, 'application/pdf')],
        ]);
        $attachment = $task->evidence()->firstOrFail();

        $this->actingAs($officer)->get(route('evidence.preview', $attachment))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        $this->actingAs($other)->get(route('evidence.preview', $attachment))->assertForbidden();
    }

    public function test_word_document_has_an_in_system_text_preview(): void
    {
        Storage::fake('evidence');
        $officer = User::factory()->role(Role::Officer)->create();
        $task = $this->taskFor($officer);
        $temporary = tempnam(sys_get_temp_dir(), 'ats-docx-');
        $zip = new \ZipArchive;
        $zip->open($temporary, \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .'<w:p><w:r><w:t>Implementation progress report</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $key = $task->id.'/brief.docx';
        Storage::disk('evidence')->put($key, file_get_contents($temporary));
        unlink($temporary);

        $attachment = EvidenceAttachment::create([
            'task_id' => $task->id,
            'source_type' => 'file',
            'original_filename' => 'brief.docx',
            'storage_key' => $key,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size_bytes' => Storage::disk('evidence')->size($key),
            'checksum' => hash('sha256', 'brief-docx'),
            'uploaded_by_user_id' => $officer->id,
            'uploaded_at' => now(),
        ]);

        $this->actingAs($officer)->get(route('evidence.preview', $attachment))
            ->assertOk()
            ->assertSeeText('Implementation progress report');
    }

    public function test_completion_requires_100_percent_progress()
    {
        Storage::fake('evidence');
        $officer = User::factory()->role(Role::Officer)->create();
        $task = $this->taskFor($officer);

        $this->actingAs($officer)->post("/tasks/{$task->id}/progress", [
            'status' => 'completed',
            'progress' => 80,
            'note' => 'Nearly done.',
            'evidence' => [UploadedFile::fake()->create('report.pdf', 100, 'application/pdf')],
        ])->assertSessionHasErrors('progress');
    }

    public function test_only_the_assignee_may_update_progress()
    {
        $officer = User::factory()->role(Role::Officer)->create();
        $other = User::factory()->role(Role::Officer)->create();
        $task = $this->taskFor($officer);

        $this->actingAs($other)->post("/tasks/{$task->id}/progress", [
            'status' => 'in_progress',
            'progress' => 25,
            'note' => 'Trying to update someone else\'s task.',
        ])->assertForbidden();
    }

    public function test_executable_uploads_are_rejected()
    {
        Storage::fake('evidence');
        $officer = User::factory()->role(Role::Officer)->create();
        $task = $this->taskFor($officer);

        $this->actingAs($officer)->post("/tasks/{$task->id}/progress", [
            'status' => 'in_progress',
            'progress' => 25,
            'note' => 'Attaching a file.',
            'evidence' => [UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload')],
        ])->assertSessionHasErrors();
    }

    public function test_officer_cannot_view_unrelated_task_by_direct_url()
    {
        $officer = User::factory()->role(Role::Officer)->create();
        $task = $this->taskFor(User::factory()->role(Role::Officer)->create());

        $this->actingAs($officer)->get("/tasks/{$task->id}")->assertForbidden();
    }

    public function test_annotation_notifies_assignee_and_is_recorded()
    {
        $officer = User::factory()->role(Role::Officer)->create();
        $task = $this->taskFor($officer);
        $supervisor = User::find($task->assigned_by_user_id);

        $this->actingAs($supervisor)->post("/tasks/{$task->id}/annotations", [
            'text' => 'Please expedite.',
        ])->assertRedirect();

        $this->assertDatabaseHas('task_histories', ['task_id' => $task->id, 'action_type' => 'Annotated', 'note' => 'Please expedite.']);
        $this->assertSame(1, Notification::where('user_id', $officer->id)->where('type', 'annotation')->count());
        $this->assertNotNull($supervisor);
    }

    public function test_officer_can_annotate_a_permitted_task_but_not_an_unrelated_task()
    {
        $officer = User::factory()->role(Role::Officer)->create();
        $task = $this->taskFor($officer);

        $this->actingAs($officer)->post("/tasks/{$task->id}/annotations", [
            'text' => 'Officer note.',
        ])->assertRedirect();

        $this->assertDatabaseHas('task_histories', ['task_id' => $task->id, 'action_type' => 'Annotated', 'note' => 'Officer note.']);

        $unrelated = $this->taskFor(User::factory()->role(Role::Officer)->create());
        $this->actingAs($officer)->post("/tasks/{$unrelated->id}/annotations", [
            'text' => 'Hidden note.',
        ])->assertForbidden();
    }
}
