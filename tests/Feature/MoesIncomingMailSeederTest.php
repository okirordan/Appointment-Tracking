<?php

namespace Tests\Feature;

use App\Models\MailRecord;
use App\Models\User;
use Database\Seeders\MoesIncomingMailSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoesIncomingMailSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_every_workbook_row_and_is_idempotent(): void
    {
        $clerk = User::factory()->create(['username' => 'machieng']);

        $this->seed(MoesIncomingMailSeeder::class);
        $this->seed(MoesIncomingMailSeeder::class);

        $this->assertDatabaseCount('mail_records', 532);
        $this->assertDatabaseHas('mail_records', [
            'register_number' => 'IM-MOES-00001',
            'direction' => 'incoming',
            'sender_name' => 'Georgia Gorretie Nakalyowa',
            'recipient_name' => 'cc PS/ES',
            'subject' => 'Jessie Joy Nalugwa',
            'received_date' => '2025-02-18',
            'correspondence_reference' => 'Georgia Gorretie Nakalyowa',
            'captured_by_user_id' => $clerk->id,
        ]);
        $this->assertDatabaseHas('mail_records', [
            'register_number' => 'IM-MOES-00205',
            'received_date' => '2026-04-07',
        ]);
        $this->assertDatabaseHas('mail_records', [
            'register_number' => 'IM-MOES-00403',
            'received_date' => '2024-03-25',
        ]);

        $this->assertSame(
            5,
            MailRecord::query()
                ->where('sender_name', 'AC/CIM')
                ->where('subject', 'Invitation to participate in the user acceptance testing of HCM and TMIS HCM phase II at the civil service college Jinja')
                ->count(),
        );
    }
}
