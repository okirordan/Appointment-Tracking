<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\MailRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BasicExternalSourcesTest extends TestCase
{
    use RefreshDatabase;

    private function incoming(string $name, string $subject, array $extra = []): array
    {
        return [
            'sender_name' => $name, 'recipient_name' => 'Permanent Secretary', 'subject' => $subject,
            'basic_source_key' => 'new', 'basic_source_kind' => 'organization',
            'received_date' => today()->toDateString(), 'priority' => 'medium', 'confidentiality' => 'normal',
            ...$extra,
        ];
    }

    public function test_custom_incoming_source_is_saved_linked_and_reused_by_name(): void
    {
        $actor = User::factory()->role(Role::Ps)->create();
        $this->actingAs($actor)->post(route('mail.incoming.store', ['mode' => 'basic']),
            $this->incoming('  Uganda   National ICT Association  ', 'First association letter'))
            ->assertSessionHasNoErrors()->assertRedirect();
        $first = MailRecord::where('subject', 'First association letter')->firstOrFail();
        $this->assertNotNull($first->external_source_id);
        $this->assertSame('Uganda National ICT Association', $first->externalMailSource->name);
        $this->assertSame('organization', $first->externalMailSource->kind);
        $this->getJson(route('mail.directory', ['q' => 'national ict', 'purpose' => 'source']))->assertOk()
            ->assertJsonFragment(['key' => 'external:'.$first->external_source_id, 'value' => 'Uganda National ICT Association']);

        $this->post(route('mail.incoming.store', ['mode' => 'basic']),
            $this->incoming('uganda national   ICT ASSOCIATION', 'Second association letter'))
            ->assertSessionHasNoErrors()->assertRedirect();
        $second = MailRecord::where('subject', 'Second association letter')->firstOrFail();
        $this->assertSame($first->external_source_id, $second->external_source_id);
        $this->assertDatabaseCount('external_mail_sources', 1);
    }

    public function test_failed_mail_does_not_create_a_source(): void
    {
        $actor = User::factory()->role(Role::Ps)->create();
        $this->actingAs($actor)->post(route('mail.incoming.store', ['mode' => 'basic']),
            $this->incoming('Never Saved Association', ''))
            ->assertSessionHasErrors('subject');
        $this->assertDatabaseCount('external_mail_sources', 0);
    }

    public function test_mail_save_failure_rolls_back_new_source(): void
    {
        $actor = User::factory()->role(Role::Ps)->create();
        MailRecord::creating(function ($record) {
            if ($record->subject === 'Simulated failed capture') {
                throw ValidationException::withMessages(['subject' => 'Simulated save failure']);
            }
        });
        $this->actingAs($actor)->post(route('mail.incoming.store', ['mode' => 'basic']),
            $this->incoming('Unsaved Association', 'Simulated failed capture'))
            ->assertSessionHasErrors('subject');
        $this->assertDatabaseCount('external_mail_sources', 0);
        $this->assertDatabaseCount('mail_records', 0);
    }

    public function test_full_mode_plain_external_sender_remains_a_snapshot(): void
    {
        $actor = User::factory()->role(Role::Ps)->create();
        $this->actingAs($actor)->post(route('mail.incoming.store', ['mode' => 'full']), [
            'sender_name' => 'Unregistered Full Mode Sender', 'recipient_name' => 'Permanent Secretary',
            'subject' => 'Full Mode untouched', 'received_date' => today()->toDateString(),
            'priority' => 'medium', 'confidentiality' => 'normal',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNull(MailRecord::where('subject', 'Full Mode untouched')->firstOrFail()->external_source_id);
        $this->assertDatabaseCount('external_mail_sources', 0);
    }
}
