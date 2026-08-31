<?php

namespace Tests\Feature\Admin;

use App\Enums\Role as SystemRole;
use App\Models\Department;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPosition;
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

        $this->actingAs($admin)->post(route('admin.hierarchy.units.store'), [
            'name' => 'Procurement and Disposal Unit',
            'code' => 'PDU',
            'type' => 'unit',
            'parent_id' => null,
            'department_id' => null,
            'division_id' => null,
            'reason' => 'Created pending confirmation of the parent department.',
        ])->assertSessionHasNoErrors();

        $unit = OrganizationalUnit::where('code', 'PDU')->firstOrFail();
        $this->assertNull($unit->department_id);

        $officer = User::factory()->role(SystemRole::Officer)->create([
            'organizational_unit_id' => $unit->id,
            'department_id' => null,
        ]);

        $this->actingAs($admin)->put(route('admin.hierarchy.units.update', $unit), [
            'name' => $unit->name,
            'code' => $unit->code,
            'type' => $unit->type,
            'parent_id' => null,
            'department_id' => $department->id,
            'division_id' => null,
            'active' => true,
            'reason' => 'PDU confirmed as a Finance and Administration unit.',
        ])->assertSessionHasNoErrors();

        $this->assertSame($department->id, $unit->fresh()->department_id);
        $this->assertSame($department->id, $officer->fresh()->department_id);
        $this->assertDatabaseHas('user_profile_changes', [
            'user_id' => $officer->id,
            'field_name' => 'department_id',
            'new_value' => (string) $department->id,
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
}
