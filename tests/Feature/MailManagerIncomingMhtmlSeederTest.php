<?php

namespace Tests\Feature;

use App\Models\MailRecord;
use App\Models\User;
use Database\Seeders\MailManagerIncomingMailSeeder;
use Database\Seeders\MailManagerIncomingMhtmlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailManagerIncomingMhtmlSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_missing_rows_recovers_the_partial_row_and_is_idempotent(): void
    {
        $clerk = User::factory()->create(['username' => 'machieng']);

        $this->seed(MailManagerIncomingMailSeeder::class);
        $this->seed(MailManagerIncomingMhtmlSeeder::class);
        $this->seed(MailManagerIncomingMhtmlSeeder::class);

        $this->assertDatabaseCount('mail_records', 24893);
        $this->assertSame(
            4996,
            MailRecord::query()
                ->where('external_id', 'like', 'mail-manager-incoming-mhtml-2026-07-23:%')
                ->count(),
        );
        $this->assertDatabaseHas('mail_records', [
            'direction' => 'incoming',
            'sender_name' => 'Dr Jane Egau Okou',
            'recipient_name' => 'cc PS/ES',
            'received_date' => '2025-04-15',
            'correspondence_reference' => 'USFA',
        ]);
        $this->assertDatabaseHas('mail_records', [
            'register_number' => 'IM-MMH-24891',
            'sender_name' => 'Dr Ssekiranda Muhammad',
            'subject' => 'Recommendation for appointment of kazibwe ali as acting deputy headteacher',
            'received_date' => '2025-03-11',
            'correspondence_reference' => 'Katooke Moslem SS',
            'captured_by_user_id' => $clerk->id,
        ]);
        $this->assertDatabaseHas('mail_records', [
            'register_number' => 'IM-MMH-24892',
            'sender_name' => 'Patrick E Muinda',
            'subject' => 'Request to pay M/S MFI document solutions ltd for procurement of laptops with software license multi purpose printers projectors and network equipment for',
            'received_date' => null,
            'correspondence_reference' => null,
            'details' => 'Source: Mail Manager incoming MHTML row 24892. The source row was incomplete; its printed received date and reference were missing.',
        ]);
    }
}
