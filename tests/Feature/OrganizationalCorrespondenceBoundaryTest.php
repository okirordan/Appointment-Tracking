<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CorrespondenceForward;
use App\Models\CorrespondenceRecipient;
use App\Models\Department;
use App\Models\Division;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\SecretaryOfficeAttachment;
use App\Models\Task;
use App\Models\User;
use App\Services\SecretaryOfficeScope;
use App\Services\Tasks\TaskScope;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationalCorrespondenceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_division_secretaries_only_see_owned_or_explicitly_routed_correspondence(): void
    {
        [$department, $divisionA, $divisionB, $unitA, $unitB] = $this->divisionStructure();
        $head = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
        $department->update(['head_user_id' => $head->id, 'head_name' => $head->full_name]);
        $secretaryA = $this->divisionSecretary($department, $divisionA, $unitA, $head, 'Division A Secretary');
        $secretaryB = $this->divisionSecretary($department, $divisionB, $unitB, $head, 'Division B Secretary');
        // The current attachment is authoritative even if a stale profile or
        // position still points at the former office.
        $secretaryA->forceFill([
            'organizational_unit_id' => $unitB->id,
            'division_id' => $divisionB->id,
        ])->save();

        $mailA = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $secretaryA->id,
            'organizational_unit_id' => $unitA->id,
            'department_id' => $department->id,
            'office_supervisor_user_id' => $head->id,
            'subject' => 'Division A private correspondence',
        ]);
        $mailB = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $secretaryB->id,
            'organizational_unit_id' => $unitB->id,
            'department_id' => $department->id,
            'office_supervisor_user_id' => $head->id,
            'subject' => 'Division B private correspondence',
        ]);

        $this->assertTrue($secretaryA->can('view', $mailA));
        $this->assertFalse($secretaryA->can('view', $mailB));
        $this->assertTrue($secretaryB->can('view', $mailB));
        $this->assertFalse($secretaryB->can('view', $mailA));
        $this->assertTrue($head->can('view', $mailA));
        $this->assertTrue($head->can('view', $mailB));

        $forward = CorrespondenceForward::create([
            'correspondence_id' => $mailB->correspondence_id,
            'forwarded_by_user_id' => $secretaryB->id,
            'from_organizational_unit_id' => $unitB->id,
            'instructions' => 'Formally routed to Division A.',
            'status' => 'sent',
            'forwarded_at' => now(),
        ]);
        CorrespondenceRecipient::create([
            'correspondence_id' => $mailB->correspondence_id,
            'correspondence_forward_id' => $forward->id,
            'recipient_type' => 'to',
            'purpose' => 'information',
            'target_type' => 'office',
            'organizational_unit_id' => $unitA->id,
            'department_id' => $department->id,
            'recipient_name_snapshot' => $unitA->name,
            'active' => true,
            'added_by_user_id' => $secretaryB->id,
            'added_at' => now(),
        ]);

        $this->assertTrue($secretaryA->can('view', $mailB));
    }

    public function test_explicit_entity_assignment_is_exact_even_without_a_legacy_secretary_attachment(): void
    {
        [$department, $divisionA, $divisionB, $unitA, $unitB] = $this->divisionStructure();
        $secretary = User::factory()->role(Role::Secretary)->create([
            'department_id' => $department->id,
            'division_id' => $divisionA->id,
            'organizational_unit_id' => $unitA->id,
        ]);
        $officerA = User::factory()->role(Role::Officer)->create([
            'department_id' => $department->id,
            'division_id' => $divisionA->id,
            'organizational_unit_id' => $unitA->id,
        ]);
        $officerB = User::factory()->role(Role::Officer)->create([
            'department_id' => $department->id,
            'division_id' => $divisionB->id,
            'organizational_unit_id' => $unitB->id,
        ]);
        $taskA = Task::factory()->create([
            'assigned_to_user_id' => $officerA->id,
            'current_assignee_user_id' => $officerA->id,
            'owner_organizational_unit_id' => $unitA->id,
            'department_id' => $department->id,
            'division_id' => $divisionA->id,
        ]);
        $taskB = Task::factory()->create([
            'assigned_to_user_id' => $officerB->id,
            'current_assignee_user_id' => $officerB->id,
            'owner_organizational_unit_id' => $unitB->id,
            'department_id' => $department->id,
            'division_id' => $divisionB->id,
        ]);

        $visibleIds = app(SecretaryOfficeScope::class)
            ->tasks($secretary)
            ->pluck('tasks.id')
            ->all();

        $this->assertContains($taskA->id, $visibleIds);
        $this->assertNotContains($taskB->id, $visibleIds);

        $taskListIds = app(TaskScope::class)->query($secretary)->pluck('tasks.id')->all();
        $this->assertContains($taskA->id, $taskListIds);
        $this->assertNotContains($taskB->id, $taskListIds);
        $this->assertTrue($secretary->can('create', MailRecord::class));
    }

    public function test_division_secretary_task_scope_excludes_sibling_division_work(): void
    {
        [$department, $divisionA, $divisionB, $unitA, $unitB] = $this->divisionStructure();
        $head = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
        $secretaryA = $this->divisionSecretary($department, $divisionA, $unitA, $head, 'Division A Secretary');
        $officerA = User::factory()->role(Role::Officer)->create([
            'department_id' => $department->id,
            'division_id' => $divisionA->id,
            'organizational_unit_id' => $unitA->id,
        ]);
        $officerB = User::factory()->role(Role::Officer)->create([
            'department_id' => $department->id,
            'division_id' => $divisionB->id,
            'organizational_unit_id' => $unitB->id,
        ]);
        $visible = Task::factory()->create([
            'assigned_by_user_id' => $head->id,
            'assigned_to_user_id' => $officerA->id,
            'current_assignee_user_id' => $officerA->id,
            'responsible_user_id' => $officerA->id,
            'department_id' => $department->id,
            'division_id' => $divisionA->id,
        ]);
        $hidden = Task::factory()->create([
            'assigned_by_user_id' => $head->id,
            'assigned_to_user_id' => $officerB->id,
            'current_assignee_user_id' => $officerB->id,
            'responsible_user_id' => $officerB->id,
            'department_id' => $department->id,
            'division_id' => $divisionB->id,
        ]);

        $this->actingAs($secretaryA)->get(route('tasks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tasks.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$visible->id]));
        $this->actingAs($secretaryA)->get(route('tasks.show', $hidden))->assertForbidden();
    }

    public function test_internal_route_is_outgoing_for_sender_and_incoming_for_recipient_without_duplication(): void
    {
        [$department, $divisionA, $divisionB, $unitA, $unitB] = $this->divisionStructure();
        $head = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
        $secretaryA = $this->divisionSecretary($department, $divisionA, $unitA, $head, 'Division A Secretary');
        $secretaryB = $this->divisionSecretary($department, $divisionB, $unitB, $head, 'Division B Secretary');
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $secretaryA->id,
            'organizational_unit_id' => $unitA->id,
            'department_id' => $department->id,
            'office_supervisor_user_id' => $head->id,
            'subject' => 'Shared division routing marker',
        ]);

        $this->actingAs($secretaryA)->post(route('mail.assign', $mail), [
            'target_type' => 'office',
            'organizational_unit_id' => $unitB->id,
            'action_required' => false,
            'priority' => 'medium',
            'instructions' => 'Please receive this in Division B.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('mail_records', 1);
        $this->assertDatabaseCount('correspondences', 1);
        $this->assertDatabaseHas('correspondence_recipients', [
            'correspondence_id' => $mail->correspondence_id,
            'organizational_unit_id' => $unitB->id,
            'routing_status' => 'received',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $secretaryB->id,
            'type' => 'correspondence_forwarded',
            'related_mail_record_id' => $mail->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $secretaryA->id,
            'type' => 'correspondence_forwarded',
            'related_mail_record_id' => $mail->id,
        ]);

        $this->actingAs($secretaryA)->get(route('mail.outgoing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mails.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$mail->id]));
        $this->actingAs($secretaryB)->get(route('mail.incoming.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mails.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$mail->id])
                ->where('mails.data.0.mailbox_direction', 'incoming'));
        $this->actingAs($secretaryB)->get(route('mail.outgoing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('mails.data', 0));
        $this->actingAs($secretaryA)->get(route('mail.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedMail.mailbox_direction', 'outgoing')
                ->where('selectedMail.record_kind', 'Outgoing · Forwarded'));
        $this->actingAs($secretaryB)->get(route('mail.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedMail.mailbox_direction', 'incoming')
                ->where('selectedMail.record_kind', 'Incoming')
                ->where('selectedMail.movement_history.0.from', $unitA->name)
                ->where('selectedMail.movement_history.0.to', $unitB->name)
                ->where('selectedMail.movement_history.0.routing_status', 'Received'));
        $this->actingAs($secretaryA)->get(route('secretary.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.incoming', 0)
                ->where('stats.outgoing', 1));
        $this->actingAs($secretaryB)->get(route('secretary.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.incoming', 1)
                ->where('stats.outgoing', 0));
    }

    public function test_named_ministerial_offices_are_independent_and_isolated(): void
    {
        $names = [
            'Office of the Minister of Education and Sports',
            'Office of the Minister of State for Sports',
            'Office of the Minister of State for Primary Education',
            'Office of the Minister of State for Higher Education',
        ];
        $offices = OrganizationalUnit::query()->whereIn('name', $names)->get();

        $this->assertCount(4, $offices);
        $this->assertTrue($offices->every(fn (OrganizationalUnit $office) => $office->type === 'office'
            && $office->department_id === null
            && $office->division_id === null
        ));

        $ministerA = User::factory()->role(Role::Officer)->create(['title' => 'Minister A']);
        $ministerB = User::factory()->role(Role::Officer)->create(['title' => 'Minister B']);
        $secretaryA = $this->independentOfficeSecretary($offices[0], $ministerA, 'Minister A Secretary');
        $secretaryB = $this->independentOfficeSecretary($offices[1], $ministerB, 'Minister B Secretary');
        $mailA = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $secretaryA->id,
            'organizational_unit_id' => $offices[0]->id,
            'department_id' => null,
            'office_supervisor_user_id' => $ministerA->id,
        ]);
        $mailB = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $secretaryB->id,
            'organizational_unit_id' => $offices[1]->id,
            'department_id' => null,
            'office_supervisor_user_id' => $ministerB->id,
        ]);

        $this->assertTrue($secretaryA->can('view', $mailA));
        $this->assertFalse($secretaryA->can('view', $mailB));
        $this->assertTrue($secretaryB->can('view', $mailB));
        $this->assertFalse($secretaryB->can('view', $mailA));
    }

    public function test_directly_assigned_independent_office_secretary_can_manage_only_that_office_register(): void
    {
        $office = OrganizationalUnit::where('code', 'OSMS')->firstOrFail();
        $otherOffice = OrganizationalUnit::where('code', 'OSMPE')->firstOrFail();
        $secretary = User::factory()->role(Role::Secretary)->create([
            'department_id' => null,
            'division_id' => null,
            'organizational_unit_id' => $office->id,
        ]);
        $owned = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $secretary->id,
            'organizational_unit_id' => $office->id,
            'department_id' => null,
        ]);
        $other = MailRecord::factory()->incoming()->create([
            'organizational_unit_id' => $otherOffice->id,
            'department_id' => null,
        ]);

        $this->assertTrue($secretary->can('create', MailRecord::class));
        $this->assertTrue($secretary->can('view', $owned));
        $this->assertFalse($secretary->can('view', $other));
    }

    public function test_admin_can_move_secretary_owned_history_without_rewriting_routing_targets(): void
    {
        [$department, $divisionA, $divisionB, $unitA, $unitB] = $this->divisionStructure();
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $head = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
        $secretary = User::factory()->role(Role::Secretary)->create(['department_id' => $department->id]);
        $legacyDepartmentUnit = OrganizationalUnit::create([
            'department_id' => $department->id,
            'type' => 'department',
            'name' => 'Legacy department register',
            'code' => 'LEGACY-DEPT',
            'active' => true,
        ]);
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $secretary->id,
            'organizational_unit_id' => $legacyDepartmentUnit->id,
            'department_id' => $department->id,
            'office_supervisor_user_id' => $head->id,
        ]);
        $forward = CorrespondenceForward::create([
            'correspondence_id' => $mail->correspondence_id,
            'forwarded_by_user_id' => $secretary->id,
            'from_organizational_unit_id' => $legacyDepartmentUnit->id,
            'status' => 'sent',
            'forwarded_at' => now(),
        ]);
        $recipient = CorrespondenceRecipient::create([
            'correspondence_id' => $mail->correspondence_id,
            'correspondence_forward_id' => $forward->id,
            'recipient_type' => 'to',
            'purpose' => 'information',
            'target_type' => 'office',
            'organizational_unit_id' => $unitB->id,
            'department_id' => $department->id,
            'recipient_name_snapshot' => $unitB->name,
            'active' => true,
            'added_by_user_id' => $secretary->id,
            'added_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('admin.hierarchy.secretary-attachments.store'), [
            'secretary_user_id' => $secretary->id,
            'supervisor_user_id' => $head->id,
            'organizational_unit_id' => $unitA->id,
            'official_job_title' => 'Division Secretary',
            'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'delegated_actions_permitted' => false,
            'move_existing_correspondence' => true,
            'reason' => 'Assign the historical register to Division A.',
        ])->assertSessionHasNoErrors();

        $this->assertSame($unitA->id, $secretary->refresh()->organizational_unit_id);
        $this->assertSame($unitA->id, $mail->refresh()->organizational_unit_id);
        $this->assertSame($unitA->id, $mail->correspondence->organizational_unit_id);
        $this->assertSame($unitB->id, $recipient->refresh()->organizational_unit_id);
        $this->assertSame($legacyDepartmentUnit->id, $forward->refresh()->from_organizational_unit_id);
        $this->assertDatabaseHas('audit_logs', [
            'category' => 'user',
            'target_type' => 'SecretaryOfficeAttachment',
        ]);
    }

    /** @return array{Department, Division, Division, OrganizationalUnit, OrganizationalUnit} */
    private function divisionStructure(): array
    {
        $department = Department::factory()->create(['name' => 'Shared Department', 'code' => 'SHARED']);
        $divisionA = Division::factory()->create(['department_id' => $department->id, 'name' => 'Division A', 'code' => 'DIV-A']);
        $divisionB = Division::factory()->create(['department_id' => $department->id, 'name' => 'Division B', 'code' => 'DIV-B']);
        $unitA = OrganizationalUnit::create([
            'department_id' => $department->id,
            'division_id' => $divisionA->id,
            'type' => 'division',
            'name' => 'Division A',
            'code' => 'ORG-DIV-A',
            'active' => true,
        ]);
        $unitB = OrganizationalUnit::create([
            'department_id' => $department->id,
            'division_id' => $divisionB->id,
            'type' => 'division',
            'name' => 'Division B',
            'code' => 'ORG-DIV-B',
            'active' => true,
        ]);

        return [$department, $divisionA, $divisionB, $unitA, $unitB];
    }

    private function divisionSecretary(
        Department $department,
        Division $division,
        OrganizationalUnit $unit,
        User $supervisor,
        string $name,
    ): User {
        $secretary = User::factory()->role(Role::Secretary)->create([
            'full_name' => $name,
            'department_id' => $department->id,
            'division_id' => $division->id,
            'organizational_unit_id' => $unit->id,
            'supervisor_user_id' => $supervisor->id,
        ]);
        SecretaryOfficeAttachment::create([
            'secretary_user_id' => $secretary->id,
            'supervisor_user_id' => $supervisor->id,
            'organizational_unit_id' => $unit->id,
            'official_job_title' => 'Division Secretary',
            'starts_at' => now()->subMinute(),
            'delegated_actions_permitted' => false,
            'active' => true,
        ]);

        return $secretary;
    }

    private function independentOfficeSecretary(OrganizationalUnit $office, User $supervisor, string $name): User
    {
        $secretary = User::factory()->role(Role::Secretary)->create([
            'full_name' => $name,
            'organizational_unit_id' => $office->id,
            'supervisor_user_id' => $supervisor->id,
        ]);
        SecretaryOfficeAttachment::create([
            'secretary_user_id' => $secretary->id,
            'supervisor_user_id' => $supervisor->id,
            'organizational_unit_id' => $office->id,
            'official_job_title' => 'Personal Secretary',
            'starts_at' => now()->subMinute(),
            'delegated_actions_permitted' => false,
            'active' => true,
        ]);

        return $secretary;
    }
}
