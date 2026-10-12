<?php

namespace Tests\Feature;

use App\Models\CorrespondenceUpdate;
use App\Models\MailRecord;
use App\Services\Imports\ImportedOutgoingMailRepairService;
use App\Services\Mail\MailRecordPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportedOutgoingMailRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_preserves_the_subject_and_import_payload_and_routes_existing_correspondence(): void
    {
        $payload = json_encode([
            'MailDetails' => 'Different source mail text',
            'CorrespondenceDetails' => 'Please handle this matter.',
            'To' => 'PS/ES',
            'SQLTextDifferences' => 'Original source text',
        ], JSON_THROW_ON_ERROR);
        $mail = MailRecord::factory()->create([
            'direction' => 'outgoing',
            'external_id' => 'excel:batch:outgoing:1',
            'sender_name' => 'Original external sender',
            'recipient_name' => 'CHRM',
            'subject' => 'Invitation subject',
            'details' => $payload,
            'sent_date' => '2026-09-08',
        ]);
        $note = CorrespondenceUpdate::create([
            'correspondence_id' => $mail->correspondence_id,
            'type' => 'note',
            'entry_method' => 'excel_register_20260924',
            'body' => 'Please handle this matter.',
            'performed_by_name_snapshot' => 'Imported register',
            'created_at' => now(),
        ]);

        $result = app(ImportedOutgoingMailRepairService::class)->repair();

        $this->assertSame(1, $result['repaired']);
        $mail->refresh();
        $this->assertSame('Invitation subject', $mail->subject);
        $this->assertSame('Invitation subject', $mail->details);
        $this->assertSame('PS/ES', $mail->sender_name);
        $this->assertSame('C/HRM', $mail->recipient_name);
        $this->assertDatabaseHas('mail_import_payloads', [
            'mail_record_id' => $mail->id,
            'original_sender_name' => 'Original external sender',
            'original_recipient_name' => 'CHRM',
            'raw_details' => $payload,
        ]);
        $this->assertSame('Please handle this matter.', $note->fresh()->body);
        $this->assertSame('PS/ES', $note->fresh()->performed_by_office_snapshot);
        $this->assertSame('C/HRM', $note->fresh()->destination_office_snapshot);
        $shown = app(MailRecordPresenter::class)->detail($mail)['basic_correspondences'];
        $this->assertCount(1, $shown);
        $this->assertSame('Please handle this matter.', $shown[0]['text']);
        $this->assertSame('PS/ES', $shown[0]['from_office']);
        $this->assertSame('C/HRM', $shown[0]['destination_office']);

        $this->assertSame(0, app(ImportedOutgoingMailRepairService::class)->repair()['repaired']);
        $this->assertSame(1, CorrespondenceUpdate::where('correspondence_id', $mail->correspondence_id)->count());
    }

    public function test_repair_creates_a_correspondence_note_only_when_source_text_exists(): void
    {
        $mail = MailRecord::factory()->create([
            'direction' => 'outgoing',
            'external_id' => 'excel:batch:outgoing:2',
            'sender_name' => 'Original external sender',
            'recipient_name' => 'CGSE',
            'subject' => 'Source subject',
            'details' => json_encode(['MailDetails' => 'Source subject', 'CorrespondenceDetails' => 'Forward for review', 'To' => 'PS/ES'], JSON_THROW_ON_ERROR),
        ]);
        $empty = MailRecord::factory()->create([
            'direction' => 'outgoing',
            'external_id' => 'excel:batch:outgoing:3',
            'sender_name' => 'Another sender',
            'recipient_name' => 'CHRM',
            'subject' => 'Another subject',
            'details' => json_encode(['MailDetails' => 'Another subject', 'CorrespondenceDetails' => '', 'To' => 'PS/ES'], JSON_THROW_ON_ERROR),
        ]);
        $ordinary = MailRecord::factory()->create([
            'direction' => 'outgoing',
            'external_id' => 'excel:batch:outgoing:4',
            'sender_name' => 'PS/ES',
            'recipient_name' => 'External recipient',
            'subject' => 'Ordinary mail',
            'details' => 'Dispatch by courier',
        ]);

        $this->assertSame(2, app(ImportedOutgoingMailRepairService::class)->repair()['repaired']);
        $this->assertSame('CGSE', $mail->fresh()->recipient_name);
        $this->assertDatabaseHas('correspondence_updates', [
            'correspondence_id' => $mail->correspondence_id,
            'entry_method' => 'imported_register',
            'body' => 'Forward for review',
            'destination_office_snapshot' => 'CGSE',
        ]);
        $this->assertSame(0, CorrespondenceUpdate::where('correspondence_id', $empty->correspondence_id)->count());
        $this->assertSame('Dispatch by courier', $ordinary->fresh()->details);
        $this->assertSame('PS/ES', $ordinary->fresh()->sender_name);
        $this->assertSame(2, DB::table('mail_import_payloads')->count());
    }
}
