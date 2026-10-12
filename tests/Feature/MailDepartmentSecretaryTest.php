<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\SecretaryOfficeAttachment;
use App\Models\User;
use App\Services\Mail\MailFeatureSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailDepartmentSecretaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_authorised_department_secretary_records_office_mail_and_routes_to_eligible_staff_organisation_wide(): void
    {
        app(MailFeatureSettings::class)->set('register_number', true);
        $ownDepartment = Department::factory()->create(['name' => 'Basic Education', 'code' => 'BE']);
        $otherDepartment = Department::factory()->create(['name' => 'Higher Education', 'code' => 'HE']);
        $ownOffice = OrganizationalUnit::create([
            'department_id' => $ownDepartment->id,
            'type' => 'department',
            'name' => 'Basic Education Department',
            'code' => 'BE-OFFICE',
            'active' => true,
        ]);
        $otherOffice = OrganizationalUnit::create([
            'department_id' => $otherDepartment->id,
            'type' => 'department',
            'name' => 'Higher Education Department',
            'code' => 'HE-OFFICE',
            'active' => true,
        ]);
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $ownDepartment->id]);
        $secretary = User::factory()->role(Role::Secretary)->create(['department_id' => $ownDepartment->id]);
        $ownOfficer = User::factory()->role(Role::Officer)->create([
            'full_name' => 'Basic Education Officer',
            'department_id' => $ownDepartment->id,
        ]);
        $outsideOfficer = User::factory()->role(Role::Officer)->create([
            'full_name' => 'Higher Education Officer',
            'department_id' => $otherDepartment->id,
        ]);
        SecretaryOfficeAttachment::create([
            'secretary_user_id' => $secretary->id,
            'supervisor_user_id' => $commissioner->id,
            'organizational_unit_id' => $ownOffice->id,
            'official_job_title' => 'Department Secretary',
            'starts_at' => now()->subMinute(),
            'delegated_actions_permitted' => true,
            'delegated_permissions' => ['mail.manage', 'mail.assign'],
            'active' => true,
        ]);
        $unrelatedConfidential = MailRecord::factory()->create([
            'direction' => 'incoming',
            'office_supervisor_user_id' => $outsideOfficer->id,
            'organizational_unit_id' => $otherOffice->id,
            'confidentiality' => 'confidential',
        ]);

        $this->actingAs($secretary)->post(route('mail.incoming.store'), [
            'register_number' => 'BE-IN-2026-0042',
            'sender_name' => 'District Education Office',
            'recipient_name' => 'Commissioner, Basic Education',
            'subject' => 'School inspection response',
            'received_date' => now()->toDateString(),
            'confidentiality' => 'normal',
            'priority' => 'high',
            'status' => 'registered',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $mail = MailRecord::where('register_number', 'BE-IN-2026-0042')->firstOrFail();
        $this->assertSame($secretary->id, $mail->captured_by_user_id);
        $this->assertSame($commissioner->id, $mail->office_supervisor_user_id);
        $this->assertSame($ownOffice->id, $mail->organizational_unit_id);
        $this->actingAs($secretary)->get(route('mail.show', $mail))->assertOk();
        $this->actingAs($secretary)->get(route('mail.show', $unrelatedConfidential))->assertForbidden();

        $this->actingAs($secretary)->getJson(route('mail.recipient-search', [$mail, 'q' => 'Basic Education Officer']))
            ->assertOk()
            ->assertJsonPath('recipients.0.id', $ownOfficer->id);
        $this->actingAs($secretary)->getJson(route('mail.recipient-search', [$mail, 'q' => 'Higher Education Officer']))
            ->assertOk()
            ->assertJsonPath('recipients.0.id', $outsideOfficer->id);

        $this->actingAs($secretary)->post(route('mail.assign', $mail), [
            'department_id' => $otherDepartment->id,
            'assigned_to_user_id' => $outsideOfficer->id,
            'priority' => 'high',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tasks', [
            'id' => $mail->refresh()->task_id,
            'assigned_to_user_id' => $outsideOfficer->id,
            'department_id' => $otherDepartment->id,
        ]);
    }

    public function test_department_profile_assignment_enables_mail_capture_and_department_level_assignments_without_an_attachment(): void
    {
        $department = Department::factory()->create([
            'name' => 'Department of Libraries, E-Learning and Information Technology',
            'code' => 'LEIT',
        ]);
        $outsideDepartment = Department::factory()->create(['name' => 'Higher Education', 'code' => 'HE']);
        $unit = OrganizationalUnit::create([
            'department_id' => $department->id,
            'type' => 'department',
            'name' => 'Libraries, E-Learning and Information Technology',
            'code' => 'LEIT-OFFICE',
            'active' => true,
        ]);
        $secretary = User::factory()->role(Role::Secretary)->create([
            'full_name' => 'LEIT Department Secretary',
            'department_id' => $department->id,
        ]);
        $departmentOfficer = User::factory()->role(Role::Officer)->create([
            'full_name' => 'Library Systems Officer',
            'department_id' => $department->id,
        ]);
        $outsideOfficer = User::factory()->role(Role::Officer)->create([
            'full_name' => 'Higher Education Officer',
            'department_id' => $outsideDepartment->id,
        ]);

        $this->assertFalse($secretary->currentSecretaryAttachment()->exists());
        $this->actingAs($secretary)->get(route('mail.incoming.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canManageRegister', true)
                ->where('registerOfficeName', $department->name)
                ->has('departmentOptions', 1)
                ->where('departmentOptions.0.id', $department->id));

        $this->actingAs($secretary)->post(route('mail.incoming.store'), [
            'sender_name' => 'National Library of Uganda',
            'recipient_name' => 'LEIT Department',
            'subject' => 'Digital library interoperability meeting',
            'received_date' => now()->toDateString(),
            'confidentiality' => 'normal',
            'priority' => 'medium',
            'status' => 'registered',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $mail = MailRecord::where('subject', 'Digital library interoperability meeting')->firstOrFail();
        $this->assertSame($secretary->id, $mail->captured_by_user_id);
        $this->assertSame($unit->id, $mail->organizational_unit_id);

        $this->actingAs($secretary)->post(route('mail.outgoing.store'), [
            'sender_name' => 'LEIT Department',
            'recipient_name' => 'National Library of Uganda',
            'subject' => 'Confirmation of digital library meeting',
            'sent_date' => now()->toDateString(),
            'confidentiality' => 'normal',
            'priority' => 'medium',
            'status' => 'dispatched',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('mail_records', [
            'direction' => 'outgoing',
            'subject' => 'Confirmation of digital library meeting',
            'captured_by_user_id' => $secretary->id,
            'organizational_unit_id' => $unit->id,
        ]);

        $this->actingAs($secretary)->get(route('tasks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canCreate', true));
        $this->actingAs($secretary)->getJson(route('tasks.assignee-search', ['q' => 'Library Systems']))
            ->assertOk()
            ->assertJsonPath('users.0.id', $departmentOfficer->id);
        $this->actingAs($secretary)->getJson(route('tasks.assignee-search', ['q' => 'Higher Education']))
            ->assertOk()
            ->assertJsonPath('users.0.id', $outsideOfficer->id);

        $this->actingAs($secretary)->post(route('tasks.store'), [
            'title' => 'Prepare the department digital library brief',
            'assigned_to_user_id' => $departmentOfficer->id,
            'priority' => 'medium',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tasks', [
            'title' => 'Prepare the department digital library brief',
            'assigned_by_user_id' => $secretary->id,
            'assigned_to_user_id' => $departmentOfficer->id,
            'department_id' => $department->id,
            'assignment_level' => 'department',
        ]);

        $this->actingAs($secretary)->post(route('tasks.store'), [
            'title' => 'Out-of-scope assignment',
            'assigned_to_user_id' => $outsideOfficer->id,
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tasks', [
            'title' => 'Out-of-scope assignment',
            'assigned_to_user_id' => $outsideOfficer->id,
            'department_id' => $outsideDepartment->id,
        ]);
    }
}
