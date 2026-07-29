<?php

namespace Tests\Feature;

use App\Models\MailRecord;
use App\Models\User;
use Database\Seeders\MailManagerIncomingMailSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailManagerIncomingMailSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_the_pdf_rows_deduplicates_existing_mail_and_is_idempotent(): void
    {
        $clerk = User::factory()->create(['username' => 'machieng']);

        MailRecord::factory()->create([
            'direction' => 'incoming',
            'register_number' => 'IM-EXISTING-00001',
            'sender_name' => 'Dr Jane Egau Okou',
            'recipient_name' => 'cc PS/ES',
            'subject' => 'Fourth Quarter finance committee meeting fy 2024 - 2025',
            'received_date' => '2025-04-15',
            'correspondence_reference' => 'USFA',
            'captured_by_user_id' => $clerk->id,
        ]);

        $this->seed(MailManagerIncomingMailSeeder::class);
        $this->seed(MailManagerIncomingMailSeeder::class);

        $this->assertDatabaseCount('mail_records', 19897);
        $this->assertDatabaseMissing('mail_records', [
            'register_number' => 'IM-MMP-00001',
        ]);
        $this->assertDatabaseHas('mail_records', [
            'register_number' => 'IM-MMP-00002',
            'direction' => 'incoming',
            'sender_name' => 'Simon Peter Sebulime',
            'recipient_name' => 'PS/ES',
            'received_date' => '2025-04-15',
            'correspondence_reference' => 'Managing Director-Zmowe ltd',
            'captured_by_user_id' => $clerk->id,
        ]);
        $this->assertDatabaseHas('mail_records', [
            'register_number' => 'IM-MMP-04927',
            'sender_name' => 'Kasirivu Joseph',
            'received_date' => '2023-11-06',
        ]);
        $this->assertDatabaseHas('mail_records', [
            'register_number' => 'IM-MMP-19897',
            'sender_name' => 'CBE',
            'received_date' => null,
            'details' => 'Source: Mail Manager PDF page 985, row 15. Printed received date was blank.',
        ]);
        $this->assertSame(
            19896,
            MailRecord::query()->whereNotNull('external_id')->count(),
        );
    }
}
