<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AnnotationTitle;
use App\Models\MailRecord;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;
use App\Services\Mail\MailFeatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailBasicModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_secretaries_and_permanent_secretaries_have_the_switch_on_home_and_registers(): void
    {
        foreach ([Role::Secretary, Role::Ps] as $role) {
            $user = User::factory()->role($role)->create();
            foreach (['/home?type=mail', '/incoming-mail', '/outgoing-mail'] as $url) {
                $this->actingAs($user)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                    ->where('canSwitchMailMode', true)
                    ->where('mailMode', $role === Role::Secretary ? 'basic' : 'full'));
            }
        }
    }

    public function test_preference_survives_navigation_and_is_separate_for_each_user(): void
    {
        $secretary = User::factory()->role(Role::Secretary)->create();
        $other = User::factory()->role(Role::Secretary)->create();
        $this->actingAs($secretary)->get('/incoming-mail?mode=full')->assertOk();
        $this->get('/outgoing-mail')->assertInertia(fn (Assert $page) => $page->where('mailMode', 'full'));
        $this->get('/home?type=mail&mode=invalid')->assertInertia(fn (Assert $page) => $page->where('mailMode', 'full'));
        $this->actingAs($other)->get('/incoming-mail')->assertInertia(fn (Assert $page) => $page->where('mailMode', 'basic'));
    }

    public function test_basic_and_full_share_captured_edited_records_and_optional_references(): void
    {
        $user = User::factory()->role(Role::Ps)->create();
        app(MailFeatureSettings::class)->set('correspondence_reference', true);
        foreach (['incoming', 'outgoing'] as $direction) {
            $data = ['sender_name' => 'District office', 'recipient_name' => 'Permanent Secretary',
                'subject' => "Mode parity {$direction}", 'details' => 'Shared record',
                'received_date' => $direction === 'incoming' ? '2026-10-01' : null,
                'sent_date' => $direction === 'outgoing' ? '2026-10-02' : null,
                'correspondence_reference' => '', 'priority' => 'medium', 'confidentiality' => 'normal'];
            $this->actingAs($user)->post(route("mail.{$direction}.store", ['mode' => 'basic']), $data)
                ->assertSessionHasNoErrors()->assertRedirect(route("mail.{$direction}.index", ['mode' => 'basic']));
            $mail = MailRecord::where('subject', $data['subject'])->firstOrFail();
            $basic = $this->get(route('mail.show', [$mail, 'mode' => 'basic']))->assertOk()->inertiaProps('selectedMail');
            $full = $this->get(route('mail.show', [$mail, 'mode' => 'full']))->assertOk()->inertiaProps('selectedMail');
            $this->assertSame($basic, $full);
            $this->assertNull($mail->correspondence_reference);
            if ($direction === 'outgoing') {
                $this->assertNull($mail->received_date);
            }
            $this->put(route('mail.update', [$mail, 'mode' => 'basic', 'section' => 'details']), [...$data, 'subject' => "Edited {$direction}"])
                ->assertSessionHasNoErrors()->assertRedirect(route('mail.show', [$mail, 'mode' => 'basic', 'section' => 'details']));
            $this->get(route('mail.show', [$mail, 'mode' => 'full']))->assertInertia(fn (Assert $page) => $page->where('selectedMail.subject', "Edited {$direction}"));
        }
        $this->assertDatabaseCount('mail_records', 2);
    }

    public function test_basic_recipient_filter_pagination_and_notes_use_existing_scoped_records(): void
    {
        $user = User::factory()->role(Role::Ps)->create();
        MailRecord::factory()->incoming()->count(7)->create(['recipient_name' => 'Permanent Secretary, Ministry of Education A', 'captured_by_user_id' => $user->id]);
        MailRecord::factory()->incoming()->create(['recipient_name' => 'Permanent Secretary, Ministry of Education B', 'captured_by_user_id' => $user->id]);
        $this->actingAs($user)->get('/incoming-mail?mode=basic&recipient=Permanent%20Secretary,%20Ministry%20of%20Education%20A&page=2')
            ->assertInertia(fn (Assert $page) => $page->has('mails.data', 2)->where('mails.meta.total', 7)->where('filters.recipient', 'Permanent Secretary, Ministry of Education A'));
        $mail = MailRecord::firstOrFail();
        $this->post(route('mail.updates.store', [$mail, 'mode' => 'basic', 'section' => 'correspondences']), ['type' => 'note', 'body' => 'Shared correspondence history'])
            ->assertSessionHasNoErrors()->assertRedirect(route('mail.show', [$mail, 'mode' => 'basic', 'section' => 'correspondences']));
        foreach (['basic', 'full'] as $mode) {
            $this->get(route('mail.show', [$mail, 'mode' => $mode]))->assertInertia(fn (Assert $page) => $page
                ->where('selectedMail.activity_history.0.message', 'Shared correspondence history')
                ->where('selectedMail.basic_correspondences.0.text', 'Shared correspondence history')
                ->where('selectedMail.basic_correspondences.0.destination_office', null));
        }
    }

    public function test_mode_does_not_grant_an_unattached_secretary_capture_or_out_of_scope_access(): void
    {
        $user = User::factory()->role(Role::Secretary)->create();
        $mail = MailRecord::factory()->incoming()->create(['confidentiality' => 'restricted']);
        foreach (['basic', 'full'] as $mode) {
            $this->actingAs($user)->get('/incoming-mail?mode='.$mode)->assertInertia(fn (Assert $page) => $page
                ->where('canSwitchMailMode', true)->where('canManageRegister', false)->has('mails.data', 0));
            $this->get(route('mail.show', [$mail, 'mode' => $mode]))->assertForbidden();
            $this->post(route('mail.incoming.store', ['mode' => $mode]), [])->assertForbidden();
            $this->put(route('mail.update', [$mail, 'mode' => $mode]), [])->assertForbidden();
            $this->post(route('mail.assign', [$mail, 'mode' => $mode]), [])->assertForbidden();
        }
    }

    public function test_basic_action_point_creates_a_real_task_for_the_selected_officer(): void
    {
        $sender = User::factory()->role(Role::Ps)->create();
        $officer = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $sender->id]);

        $this->actingAs($sender)->post(route('mail.assign', [$mail, 'mode' => 'basic', 'section' => 'actions']), [
            'action_required' => true,
            'target_type' => 'individual',
            'assigned_to_user_ids' => [$officer->id],
            'instructions' => 'Prepare a response for the minister.',
            'due_date' => today()->addDays(3)->toDateString(),
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();

        $task = Task::firstOrFail();
        $this->assertSame($officer->id, $task->assigned_to_user_id);
        $this->assertSame('Prepare a response for the minister.', $task->initial_instruction);
        $this->assertSame(today()->addDays(3)->toDateString(), $task->due_date?->toDateString());
        $this->assertDatabaseHas('notifications', ['user_id' => $officer->id, 'related_task_id' => $task->id]);

        $secondOfficer = User::factory()->role(Role::Officer)->create();
        $this->post(route('mail.assign', [$mail, 'mode' => 'basic', 'section' => 'actions']), [
            'action_required' => true,
            'target_type' => 'individual',
            'assigned_to_user_ids' => [$secondOfficer->id],
            'instructions' => 'Review the proposed response.',
            'due_date' => today()->addDays(4)->toDateString(),
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('tasks', 2);
        $this->assertDatabaseHas('notifications', ['user_id' => $secondOfficer->id]);

        $this->actingAs($sender)->get(route('mail.show', [$mail, 'mode' => 'basic', 'section' => 'actions']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('selectedMail.action_points', 2)
                ->where('selectedMail.action_points.0.instructions', 'Review the proposed response.')
                ->where('selectedMail.action_points.1.task_id', $task->id)
                ->where('selectedMail.action_points.1.instructions', 'Prepare a response for the minister.'));
    }

    public function test_basic_action_point_automatically_assigns_the_mail_recipient_without_an_assignee_field(): void
    {
        $sender = User::factory()->role(Role::Ps)->create();
        $officer = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $sender->id,
            'recipient_staff_user_id' => $officer->id,
        ]);

        $this->actingAs($sender)->post(route('mail.assign', [$mail, 'mode' => 'basic', 'section' => 'actions']), [
            'basic_action' => true,
            'action_required' => true,
            'instructions' => 'Prepare the response.',
            'due_date' => today()->addDays(2)->toDateString(),
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();

        $task = Task::firstOrFail();
        $this->assertSame($officer->id, $task->assigned_to_user_id);
        $this->assertDatabaseHas('notifications', ['user_id' => $officer->id, 'related_task_id' => $task->id]);
    }

    public function test_outgoing_basic_action_point_automatically_assigns_the_mail_recipient(): void
    {
        $sender = User::factory()->role(Role::Ps)->create();
        $officer = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->outgoing()->create([
            'captured_by_user_id' => $sender->id,
            'recipient_staff_user_id' => $officer->id,
        ]);

        $this->actingAs($sender)->post(route('mail.assign-outgoing', [$mail, 'mode' => 'basic', 'section' => 'actions']), [
            'basic_action' => true,
            'instructions' => 'Confirm delivery.',
            'due_date' => today()->addDays(2)->toDateString(),
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();

        $this->assertSame($officer->id, Task::firstOrFail()->assigned_to_user_id);
    }

    public function test_basic_correspondence_stores_and_presents_details_origin_destination_and_logged_date(): void
    {
        $sender = User::factory()->role(Role::Ps)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $sender->id]);

        $this->actingAs($sender)->post(route('mail.updates.store', [$mail, 'mode' => 'basic']), [
            'entry_method' => 'basic_correspondence',
            'type' => 'note',
            'body' => 'Missing destination',
            'recorded_date' => today()->toDateString(),
        ])->assertSessionHasErrors('destination_office_snapshot');
        $this->post(route('mail.updates.store', [$mail, 'mode' => 'basic']), [
            'entry_method' => 'basic_correspondence',
            'type' => 'note',
            'body' => 'Future log date',
            'destination_office_snapshot' => 'Office of the Permanent Secretary',
            'recorded_date' => today()->addDay()->toDateString(),
        ])->assertSessionHasErrors('recorded_date');

        $this->actingAs($sender)->post(route('mail.updates.store', [$mail, 'mode' => 'basic', 'section' => 'correspondences']), [
            'entry_method' => 'basic_correspondence',
            'type' => 'note',
            'body' => 'Sent for review and comment.',
            'destination_office_snapshot' => 'Office of the Permanent Secretary',
            'recorded_date' => today()->subDay()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('correspondence_updates', [
            'body' => 'Sent for review and comment.',
            'destination_office_snapshot' => 'Office of the Permanent Secretary',
            'entry_method' => 'basic_correspondence',
        ]);
        $this->get(route('mail.show', [$mail, 'mode' => 'basic', 'section' => 'correspondences']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('selectedMail.basic_correspondences', 1)
                ->where('selectedMail.basic_correspondences.0.text', 'Sent for review and comment.')
                ->where('selectedMail.basic_correspondences.0.destination_office', 'PS/ES')
                ->where('selectedMail.basic_correspondences.0.logged_date', today()->subDay()->format('d/m/Y')));
    }

    public function test_receiving_office_suggestions_include_existing_titles_and_remember_custom_offices(): void
    {
        $user = User::factory()->role(Role::Ps)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $user->id]);
        AnnotationTitle::create(['shorthand' => 'PS', 'full_title' => 'Permanent Secretary', 'active' => true]);
        Position::create(['role_id' => $user->roles()->firstOrFail()->id, 'title' => 'Senior Records Officer', 'hierarchy_level' => 1, 'active' => true]);

        $this->actingAs($user)->getJson(route('mail.correspondence-offices.index', [$mail, 'q' => 'PS']))
            ->assertOk()->assertJsonFragment(['value' => 'Permanent Secretary', 'label' => 'PS — Permanent Secretary']);
        $this->getJson(route('mail.correspondence-offices.index', [$mail, 'q' => 'Records']))
            ->assertOk()->assertJsonFragment(['value' => 'Senior Records Officer']);

        $payload = [
            'entry_method' => 'basic_correspondence', 'type' => 'note', 'body' => 'Sent for review.',
            'destination_office_snapshot' => '  Regional Field Office  ', 'recorded_date' => today()->toDateString(),
        ];
        $this->post(route('mail.updates.store', $mail), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('correspondence_office_aliases', ['name' => 'Regional Field Office']);
        $this->assertDatabaseHas('correspondence_updates', ['destination_office_snapshot' => 'Regional Field Office']);
        $this->post(route('mail.updates.store', $mail), [...$payload, 'destination_office_snapshot' => 'regional field office'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('correspondence_office_aliases', 1);
        $this->getJson(route('mail.correspondence-offices.index', [$mail, 'q' => 'Regional']))
            ->assertOk()->assertJsonFragment(['value' => 'Regional Field Office']);
        $outsider = User::factory()->role(Role::Secretary)->create();
        $this->actingAs($outsider)->getJson(route('mail.correspondence-offices.index', [$mail, 'q' => 'Regional']))->assertForbidden();
    }

    public function test_outgoing_basic_action_point_uses_outgoing_assignment_and_notifies_the_officer(): void
    {
        $sender = User::factory()->role(Role::Ps)->create();
        $officer = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->outgoing()->create(['captured_by_user_id' => $sender->id]);

        $this->actingAs($sender)->post(route('mail.assign-outgoing', [$mail, 'mode' => 'basic', 'section' => 'actions']), [
            'assigned_to_user_ids' => [$officer->id],
            'instructions' => 'Confirm the district received the circular.',
            'due_date' => today()->addDays(2)->toDateString(),
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();

        $task = Task::firstOrFail();
        $this->assertSame($officer->id, $task->assigned_to_user_id);
        $this->assertDatabaseHas('notifications', ['user_id' => $officer->id, 'related_task_id' => $task->id]);
        $this->get(route('mail.show', [$mail, 'mode' => 'basic', 'section' => 'actions']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedMail.action_points.0.task_id', $task->id)
                ->where('selectedMail.action_points.0.instructions', 'Confirm the district received the circular.'));
    }
}
