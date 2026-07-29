<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\ImportBatch;
use App\Models\MailRecord;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class MailImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_incoming_mail_csv_is_staged_previewed_and_confirmed_with_familiar_headers(): void
    {
        Storage::fake('local');
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $csv = implode("\n", [
            'FROM,TO,SUBJECT,DATE RECEIVED,REF NO,DETAILS',
            'Office of the Auditor General,PS/ES,Management letter,18/02/2025,OAG/25/14,Review and respond',
            'Office of the Auditor General,PS/ES,Management letter,18/02/2025,OAG/25/14,Review and respond',
        ])."\n";

        $this->actingAs($admin)->post(route('admin.imports.store'), [
            'source_system' => 'MOES Incoming Register',
            'entity_type' => 'incoming_mail',
            'file' => UploadedFile::fake()->createWithContent('incoming-mail.csv', $csv),
        ])->assertSessionMissing('error')->assertRedirect();

        $batch = ImportBatch::firstOrFail();
        $this->assertSame('ready', $batch->status);
        $this->assertSame(2, $batch->valid_rows);
        $this->assertDatabaseCount('mail_records', 0);

        $this->actingAs($admin)->post(route('admin.imports.confirm', $batch))->assertRedirect();

        $this->assertDatabaseCount('mail_records', 2);
        $this->assertDatabaseHas('mail_records', [
            'direction' => 'incoming',
            'external_id' => 'MOES Incoming Register:incoming_mail:row:000002',
            'sender_name' => 'Office of the Auditor General',
            'recipient_name' => 'PS/ES',
            'subject' => 'Management letter',
            'received_date' => '2025-02-18 00:00:00',
            'correspondence_reference' => 'OAG/25/14',
            'captured_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('mail_records', [
            'external_id' => 'MOES Incoming Register:incoming_mail:row:000003',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'category' => 'import',
            'target_type' => 'ImportBatch',
            'target_id' => $batch->id,
        ]);
    }

    public function test_outgoing_mail_xlsx_import_updates_by_stable_external_id(): void
    {
        Storage::fake('local');
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $xlsx = $this->xlsxUpload([
            ['EXTERNAL ID', 'FROM', 'TO', 'SUBJECT', 'DATE SENT', 'REF NO', 'DETAILS'],
            ['OUT-001', 'PS/ES', 'Chief Administrative Officer', 'Approved response', new DateTimeImmutable('2026-07-20'), 'MOES/OUT/001', 'Dispatch by email'],
        ]);

        $this->actingAs($admin)->post(route('admin.imports.store'), [
            'source_system' => 'Executive Outbox',
            'entity_type' => 'outgoing_mail',
            'file' => $xlsx,
        ])->assertRedirect();
        $firstBatch = ImportBatch::firstOrFail();
        $this->assertSame('ready', $firstBatch->status);

        $this->actingAs($admin)->post(route('admin.imports.confirm', $firstBatch))->assertRedirect();
        $this->assertDatabaseHas('mail_records', [
            'direction' => 'outgoing',
            'external_id' => 'Executive Outbox:outgoing_mail:OUT-001',
            'sender_name' => 'PS/ES',
            'recipient_name' => 'Chief Administrative Officer',
            'subject' => 'Approved response',
            'sent_date' => '2026-07-20 00:00:00',
        ]);

        $updatedCsv = implode("\n", [
            'EXTERNAL ID,FROM,TO,SUBJECT,DATE SENT,REF NO,DETAILS',
            'OUT-001,PS/ES,Chief Administrative Officer,Revised approved response,2026-07-21,MOES/OUT/001,Dispatch by courier',
        ])."\n";
        $this->actingAs($admin)->post(route('admin.imports.store'), [
            'source_system' => 'Executive Outbox',
            'entity_type' => 'outgoing_mail',
            'file' => UploadedFile::fake()->createWithContent('outgoing-revised.csv', $updatedCsv),
        ])->assertRedirect();
        $secondBatch = ImportBatch::latest('id')->firstOrFail();
        $this->actingAs($admin)->post(route('admin.imports.confirm', $secondBatch))->assertRedirect();

        $this->assertSame(1, MailRecord::where('external_id', 'Executive Outbox:outgoing_mail:OUT-001')->count());
        $this->assertDatabaseHas('mail_records', [
            'external_id' => 'Executive Outbox:outgoing_mail:OUT-001',
            'subject' => 'Revised approved response',
            'sent_date' => '2026-07-21 00:00:00',
            'details' => 'Dispatch by courier',
        ]);
        $this->assertSame(0, $secondBatch->fresh()->created_rows);
        $this->assertSame(1, $secondBatch->fresh()->updated_rows);
    }

    public function test_outgoing_mail_accepts_register_headers_used_by_book1_workbook(): void
    {
        Storage::fake('local');
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $xlsx = $this->xlsxUpload([
            ['FROM', 'RECEIVED', 'SUBJECT', 'SENT TO', 'SENT', 'REF NO', 'DETAILS'],
            ['Patrick Ocailap', '2025-07-09', 'Project financing proposal', 'CEP', '2025-07-10', 'PS/ST', 'Review and respond'],
        ]);

        $this->actingAs($admin)->post(route('admin.imports.store'), [
            'source_system' => 'Book1 Outgoing Register',
            'entity_type' => 'outgoing_mail',
            'file' => $xlsx,
        ])->assertSessionMissing('error')->assertRedirect();

        $batch = ImportBatch::firstOrFail();
        $this->assertSame('ready', $batch->status);

        $this->actingAs($admin)->post(route('admin.imports.confirm', $batch))->assertRedirect();

        $this->assertDatabaseHas('mail_records', [
            'direction' => 'outgoing',
            'sender_name' => 'Patrick Ocailap',
            'recipient_name' => 'CEP',
            'subject' => 'Project financing proposal',
            'letter_date' => '2025-07-09 00:00:00',
            'sent_date' => '2025-07-10 00:00:00',
            'correspondence_reference' => 'PS/ST',
        ]);
    }

    public function test_invalid_mail_rows_remain_in_preview_and_cannot_be_confirmed(): void
    {
        Storage::fake('local');
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $csv = "FROM,TO,SUBJECT,DATE RECEIVED\nSender,PS/ES,Invalid date,31/02/2026\n";

        $this->actingAs($admin)->post(route('admin.imports.store'), [
            'source_system' => 'Registry Error Check',
            'entity_type' => 'incoming_mail',
            'file' => UploadedFile::fake()->createWithContent('invalid.csv', $csv),
        ])->assertRedirect();

        $batch = ImportBatch::firstOrFail();
        $this->assertSame('needs_attention', $batch->status);
        $this->assertSame(1, $batch->failed_rows);
        $this->assertDatabaseHas('import_rows', [
            'import_batch_id' => $batch->id,
            'status' => 'invalid',
        ]);

        $this->actingAs($admin)->post(route('admin.imports.confirm', $batch))->assertSessionHas('error');
        $this->assertDatabaseCount('mail_records', 0);
    }

    public function test_admin_can_download_an_xlsx_template_for_incoming_mail(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();

        $response = $this->actingAs($admin)->get(route('admin.imports.template', ['entity' => 'incoming_mail']));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('ats-incoming-mail-import-template.xlsx', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('PK', $response->streamedContent());
    }

    /**
     * @param  list<list<null|bool|DateTimeImmutable|float|int|string>>  $rows
     */
    private function xlsxUpload(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ats-mail-import-');
        if ($path === false) {
            $this->fail('Could not create the temporary XLSX path.');
        }
        $xlsxPath = $path.'.xlsx';
        @unlink($path);

        $writer = new Writer;
        $writer->openToFile($xlsxPath);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile(
            $xlsxPath,
            'outgoing-mail.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }
}
