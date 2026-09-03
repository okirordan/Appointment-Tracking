<?php

namespace Tests\Feature\Admin;

use App\Enums\Role as SystemRole;
use App\Models\Department;
use App\Models\Division;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Role;
use App\Models\SecretaryOfficeAttachment;
use App\Models\User;
use App\Models\UserPosition;
use App\Services\OrganizationalScopeService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationalPlacementManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_standalone_unit_can_be_attached_to_a_department_later(): void
    {
        $admin = User::factory()->role(SystemRole::Sysadmin)->create();
        $department = Department::factory()->create(['name' => 'Finance and Administration']);
        $departmentEntity = OrganizationalUnit::create([
            'name' => 'Department of Finance and Administration',
            'code' => 'ORG-FA',
            'type' => 'department',
            'department_id' => $department->id,
            'is_top_level' => true,
            'active' => true,
        ]);

        $this->actingAs($admin)->post(route('admin.organization-structure.entities.store'), [
            'name' => 'Procurement and Disposal Unit',
            'code' => 'PDU',
            'type' => 'unit',
            'parent_id' => null,
            'is_top_level' => true,
            'active' => true,
            'reason' => 'Created pending confirmation of the parent department.',
        ])->assertSessionHasNoErrors();

        $unit = OrganizationalUnit::where('code', 'PDU')->firstOrFail();
        $this->assertNull($unit->department_id);

        $officer = User::factory()->role(SystemRole::Officer)->create([
            'organizational_unit_id' => $unit->id,
            'department_id' => null,
        ]);

        $this->actingAs($admin)->patch(route('admin.organization-structure.entities.move', $unit), [
            'parent_id' => $departmentEntity->id,
            'is_top_level' => false,
            'reason' => 'PDU confirmed as a Finance and Administration unit.',
        ])->assertSessionHasNoErrors();

        $this->assertSame($department->id, $unit->fresh()->department_id);
        $this->assertSame($department->id, $officer->fresh()->department_id);
        $this->assertDatabaseHas('audit_logs', [
            'target_type' => 'OrganizationalUnit',
            'target_id' => $unit->id,
        ]);
    }

    public function test_admin_can_transfer_an_officer_from_an_approved_position_to_a_manual_unit(): void
    {
        $admin = User::factory()->role(SystemRole::Sysadmin)->create();
        $oldDepartment = Department::factory()->create();
        $newDepartment = Department::factory()->create();
        $oldUnit = OrganizationalUnit::create([
            'name' => 'Old Office',
            'code' => 'OLD-OFFICE',
            'type' => 'unit',
            'department_id' => $oldDepartment->id,
            'active' => true,
        ]);
        $newUnit = OrganizationalUnit::create([
            'name' => 'New Office',
            'code' => 'NEW-OFFICE',
            'type' => 'unit',
            'department_id' => $newDepartment->id,
            'active' => true,
        ]);
        $officerRole = Role::where('name', SystemRole::Officer->value)->firstOrFail();
        $oldPosition = Position::create([
            'organizational_unit_id' => $oldUnit->id,
            'role_id' => $officerRole->id,
            'title' => 'Education Officer',
            'hierarchy_level' => 100,
            'active' => true,
        ]);
        $officer = User::factory()->role(SystemRole::Officer)->create([
            'title' => $oldPosition->title,
            'department_id' => $oldDepartment->id,
            'organizational_unit_id' => $oldUnit->id,
        ]);
        $assignment = UserPosition::create([
            'user_id' => $officer->id,
            'position_id' => $oldPosition->id,
            'is_primary' => true,
            'starts_at' => now()->subYear(),
            'active' => true,
        ]);

        $this->actingAs($admin)->put(route('admin.users.update', $officer), [
            'username' => $officer->username,
            'full_name' => $officer->full_name,
            'title' => 'Senior Education Officer',
            'email' => $officer->email,
            'employee_number' => $officer->employee_number,
            'role_id' => $officerRole->id,
            'department_id' => $newDepartment->id,
            'division_id' => null,
            'organizational_unit_id' => $newUnit->id,
            'position_id' => null,
            'supervisor_user_id' => null,
            'effective_date' => '2026-08-01',
            'reason' => 'Officer transferred to the new department.',
        ])->assertSessionHasNoErrors();

        $officer->refresh();
        $this->assertSame($newDepartment->id, $officer->department_id);
        $this->assertSame($newUnit->id, $officer->organizational_unit_id);
        $this->assertSame('Senior Education Officer', $officer->title);
        $this->assertFalse($assignment->fresh()->active);
        $this->assertDatabaseHas('user_position_changes', [
            'user_id' => $officer->id,
            'previous_position_id' => $oldPosition->id,
            'new_position_id' => null,
            'reason' => 'Officer transferred to the new department.',
        ]);
    }

    public function test_moving_a_secretary_updates_their_exact_access_scope_and_ignores_legacy_placement_values(): void
    {
        $admin = User::factory()->role(SystemRole::Sysadmin)->create();
        $supervisor = User::factory()->role(SystemRole::Commissioner)->create();
        $oldDepartment = Department::factory()->create(['name' => 'Old Department']);
        $newDepartment = Department::factory()->create(['name' => 'New Department']);
        $oldDivision = Division::factory()->create(['department_id' => $oldDepartment->id, 'name' => 'Old Division']);
        $newDivision = Division::factory()->create(['department_id' => $newDepartment->id, 'name' => 'New Division']);
        $oldUnit = OrganizationalUnit::create([
            'name' => 'Old Division',
            'code' => 'OLD-DIV',
            'type' => 'division',
            'department_id' => $oldDepartment->id,
            'division_id' => $oldDivision->id,
            'active' => true,
        ]);
        $newUnit = OrganizationalUnit::create([
            'name' => 'New Division',
            'code' => 'NEW-DIV',
            'type' => 'division',
            'department_id' => $newDepartment->id,
            'division_id' => $newDivision->id,
            'active' => true,
        ]);
        $secretaryRole = Role::where('name', SystemRole::Secretary->value)->firstOrFail();
        $secretary = User::factory()->role(SystemRole::Secretary)->create([
            'department_id' => $oldDepartment->id,
            'division_id' => $oldDivision->id,
            'organizational_unit_id' => $oldUnit->id,
            'supervisor_user_id' => $supervisor->id,
        ]);
        $attachment = SecretaryOfficeAttachment::create([
            'secretary_user_id' => $secretary->id,
            'supervisor_user_id' => $supervisor->id,
            'organizational_unit_id' => $oldUnit->id,
            'official_job_title' => 'Division Secretary',
            'starts_at' => now()->subDay(),
            'active' => true,
        ]);

        $this->actingAs($admin)->put(route('admin.users.update', $secretary), [
            'username' => $secretary->username,
            'full_name' => $secretary->full_name,
            'title' => $secretary->title,
            'email' => $secretary->email,
            'employee_number' => $secretary->employee_number,
            'role_id' => $secretaryRole->id,
            'department_id' => $oldDepartment->id,
            'division_id' => $oldDivision->id,
            'organizational_unit_id' => $newUnit->id,
            'position_id' => null,
            'supervisor_user_id' => $supervisor->id,
            'effective_date' => '2026-09-02',
            'reason' => 'Secretary transferred to the correct division.',
        ])->assertSessionHasNoErrors();

        $secretary->refresh();
        $this->assertSame($newUnit->id, $secretary->organizational_unit_id);
        $this->assertSame($newDepartment->id, $secretary->department_id);
        $this->assertSame($newDivision->id, $secretary->division_id);
        $this->assertSame($newUnit->id, $attachment->fresh()->organizational_unit_id);
        $this->assertSame([$newUnit->id], app(OrganizationalScopeService::class)->unitIds($secretary));
        $this->assertDatabaseHas('user_profile_changes', [
            'user_id' => $secretary->id,
            'field_name' => 'organizational_unit_id',
            'old_value' => (string) $oldUnit->id,
            'new_value' => (string) $newUnit->id,
        ]);
    }

    public function test_admin_cannot_assign_staff_to_an_external_affiliated_body(): void
    {
        $admin = User::factory()->role(SystemRole::Sysadmin)->create();
        $department = Department::factory()->create();
        $currentUnit = OrganizationalUnit::create([
            'name' => 'Internal Registry',
            'code' => 'INT-REG',
            'type' => 'unit',
            'department_id' => $department->id,
            'active' => true,
        ]);
        $externalBody = OrganizationalUnit::create([
            'name' => 'External Agency',
            'code' => 'EXT-AGENCY',
            'type' => 'affiliated_body',
            'active' => true,
        ]);
        $officerRole = Role::where('name', SystemRole::Officer->value)->firstOrFail();
        $officer = User::factory()->role(SystemRole::Officer)->create([
            'department_id' => $department->id,
            'organizational_unit_id' => $currentUnit->id,
        ]);

        $this->actingAs($admin)->put(route('admin.users.update', $officer), [
            'username' => $officer->username,
            'full_name' => $officer->full_name,
            'title' => $officer->title,
            'email' => $officer->email,
            'employee_number' => $officer->employee_number,
            'role_id' => $officerRole->id,
            'organizational_unit_id' => $externalBody->id,
            'position_id' => null,
        ])->assertSessionHasErrors('organizational_unit_id');

        $this->assertSame($currentUnit->id, $officer->fresh()->organizational_unit_id);
    }

    public function test_selected_entity_is_authoritative_when_it_has_no_department_or_division_projection(): void
    {
        $admin = User::factory()->role(SystemRole::Sysadmin)->create();
        $oldDepartment = Department::factory()->create();
        $oldDivision = Division::factory()->create(['department_id' => $oldDepartment->id]);
        $office = OrganizationalUnit::create([
            'name' => 'Independent Executive Office',
            'code' => 'IEO',
            'type' => 'office',
            'department_id' => null,
            'division_id' => null,
            'active' => true,
        ]);
        $officerRole = Role::where('name', SystemRole::Officer->value)->firstOrFail();
        $officer = User::factory()->role(SystemRole::Officer)->create([
            'department_id' => $oldDepartment->id,
            'division_id' => $oldDivision->id,
        ]);

        $this->actingAs($admin)->put(route('admin.users.update', $officer), [
            'username' => $officer->username,
            'full_name' => $officer->full_name,
            'title' => $officer->title,
            'email' => $officer->email,
            'employee_number' => $officer->employee_number,
            'role_id' => $officerRole->id,
            'department_id' => $oldDepartment->id,
            'division_id' => $oldDivision->id,
            'organizational_unit_id' => $office->id,
            'position_id' => null,
        ])->assertSessionHasNoErrors();

        $officer->refresh();
        $this->assertSame($office->id, $officer->organizational_unit_id);
        $this->assertNull($officer->department_id);
        $this->assertNull($officer->division_id);
    }

    public function test_changing_a_secretary_to_another_role_ends_stale_secretary_access(): void
    {
        $admin = User::factory()->role(SystemRole::Sysadmin)->create();
        $supervisor = User::factory()->role(SystemRole::Commissioner)->create();
        $office = OrganizationalUnit::create([
            'name' => 'Commissioner Office',
            'code' => 'COM-OFFICE',
            'type' => 'office',
            'active' => true,
        ]);
        $secretary = User::factory()->role(SystemRole::Secretary)->create([
            'organizational_unit_id' => $office->id,
            'supervisor_user_id' => $supervisor->id,
        ]);
        $attachment = SecretaryOfficeAttachment::create([
            'secretary_user_id' => $secretary->id,
            'supervisor_user_id' => $supervisor->id,
            'organizational_unit_id' => $office->id,
            'official_job_title' => 'Secretary',
            'starts_at' => now()->subDay(),
            'active' => true,
        ]);
        $officerRole = Role::where('name', SystemRole::Officer->value)->firstOrFail();

        $this->actingAs($admin)->put(route('admin.users.update', $secretary), [
            'username' => $secretary->username,
            'full_name' => $secretary->full_name,
            'title' => 'Administrative Officer',
            'role_id' => $officerRole->id,
            'organizational_unit_id' => $office->id,
            'position_id' => null,
            'effective_date' => '2026-09-02',
            'reason' => 'Staff member reassigned from secretary duties.',
        ])->assertSessionHasNoErrors();

        $attachment->refresh();
        $this->assertFalse($attachment->active);
        $this->assertNotNull($attachment->ends_at);
        $this->assertSame($admin->id, $attachment->ended_by_user_id);
    }
}
