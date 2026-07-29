<?php

namespace Tests\Feature;

use App\Models\MailRecord;
use App\Models\User;
use Database\Seeders\MailManagerOutgoingMailSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailManagerOutgoingMailSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_the_mhtml_rows_deduplicates_existing_mail_and_is_idempotent(): void
    {
        $clerk = User::factory()->create(['username' => 'machieng']);

        MailRecord::factory()->create([
            'direction' => 'outgoing',
            'register_number' => 'OM-EXISTING-00001',
            'sender_name' => 'Ramathan Ggoobi',
            'recipient_name' => 'Existing recipient format',
            'subject' => 'Invitation to the Uganda Intergovernmental Fiscal Transfers Program for Results Implementation Completion and Results report mission, Mission March 2-15, 2026',
            'received_date' => null,
            'sent_date' => '2026-02-27',
            'captured_by_user_id' => $clerk->id,
        ]);

        $this->seed(MailManagerOutgoingMailSeeder::class);
        $this->seed(MailManagerOutgoingMailSeeder::class);

        $this->assertDatabaseCount('mail_records', 48558);
        $this->assertDatabaseMissing('mail_records', [
            'register_number' => 'OM-MMP-00001',
        ]);
        $this->assertDatabaseHas('mail_records', [
            'register_number' => 'OM-EXISTING-00001',
            'received_date' => '2026-02-27',
        ]);
        $this->assertDatabaseHas('mail_records', [
            'register_number' => 'OM-MMP-00002',
            'direction' => 'outgoing',
            'sender_name' => 'Patricia Achan Okiria [Dr.]',
            'recipient_name' => 'C/LEIT [ai], CHRM, C/EP',
            'received_date' => '2026-02-27',
            'sent_date' => '2026-02-27',
            'captured_by_user_id' => $clerk->id,
        ]);
        $this->assertDatabaseHas('mail_records', [
            'register_number' => 'OM-MMP-02999',
            'received_date' => '2026-05-08',
            'details' => "Source: Mail Manager MHTML row 2999. Printed received date '5/8/2926 12:00:00 AM' was normalized to 2026-05-08.",
        ]);
        $this->assertDatabaseHas('mail_records', [
            'register_number' => 'OM-MMP-48558',
            'sender_name' => 'Agnes Nakyoni',
            'received_date' => '2025-07-10',
            'sent_date' => '2025-07-10',
            'details' => "Source: Mail Manager MHTML row 48558. Printed sent date '7/10/0205 12:00:00 AM' was normalized to 2025-07-10.",
        ]);
        $this->assertSame(
            48557,
            MailRecord::query()->whereNotNull('external_id')->count(),
        );
    }
}
