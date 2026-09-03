<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Models\Department;
use App\Models\Division;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPosition;
use Database\Seeders\ApprovedMinistryStructureSeeder;
use Database\Seeders\CimStaffSeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_seeder_replaces_the_legacy_catalogue_and_preserves_user_links(): void
    {
        $legacy = Department::factory()->create([
            'name' => 'Basic Education',
            'code' => 'BSE',
        ]);
        $unlisted = Department::factory()->create([
            'name' => 'Unlisted Department',
            'code' => 'OLD',
        ]);
        $user = User::factory()->create(['department_id' => $legacy->id]);

        $this->seed(DepartmentSeeder::class);

        $this->assertSame(15, Department::count());
        $this->assertDatabaseHas('departments', [
            'id' => $legacy->id,
            'code' => 'PPPE',
            'name' => 'Department of Pre-Primary and Primary Education',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('departments', [
            'code' => 'EPAR',
            'name' => 'Department of Education Policy Analysis and Research',
        ]);
        $this->assertSoftDeleted('departments', ['id' => $unlisted->id]);
        $this->assertSame($legacy->id, $user->refresh()->department_id);
    }

    public function test_approved_structure_seeds_divisions_units_and_all_positions(): void
    {
        $this->seed([RoleSeeder::class, DepartmentSeeder::class, ApprovedMinistryStructureSeeder::class]);

        $this->assertSame(14, Division::count());
        $this->assertSame(48, OrganizationalUnit::count());
        $this->assertSame(296, Position::count());
        $this->assertDatabaseHas('divisions', ['name' => 'Division of Educational Information Technology Services']);
        $this->assertDatabaseHas('organizational_units', ['name' => 'Database Management Unit', 'type' => 'unit']);
        $this->assertDatabaseHas('organizational_units', ['name' => 'Office of the Permanent Secretary', 'type' => 'office']);
        $this->assertDatabaseHas('positions', ['title' => 'IT Officer – Web Master']);
        $this->assertSame(0, Position::whereNotNull('supervisor_position_id')->count());
    }

    public function test_staff_can_be_created_from_the_seeded_department_unit_and_position_options(): void
    {
        $this->seed([RoleSeeder::class, DepartmentSeeder::class, ApprovedMinistryStructureSeeder::class]);
        $admin = User::factory()->role(RoleEnum::Sysadmin)->create();
        $position = Position::where('title', 'IT Officer – Web Master')->with('organizationalUnit')->firstOrFail();
        $unit = $position->organizationalUnit;
        $role = Role::findOrFail($position->role_id);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'full_name' => 'Approved Structure User',
            'title' => '',
            'role_id' => $role->id,
            'department_id' => $unit->department_id,
            'division_id' => $unit->division_id,
            'organizational_unit_id' => $unit->id,
            'position_id' => $position->id,
            'employee_number' => 'MOES-TEST-001',
            'username' => 'approved.user',
        ])->assertSessionHasNoErrors();

        $user = User::where('username', 'approved.user')->firstOrFail();
        $this->assertSame('IT Officer – Web Master', $user->title);
        $this->assertSame($unit->department_id, $user->department_id);
        $this->assertSame($unit->division_id, $user->division_id);
        $this->assertDatabaseHas('user_positions', [
            'user_id' => $user->id,
            'position_id' => $position->id,
            'is_primary' => true,
            'active' => true,
        ]);
        $this->assertSame(1, UserPosition::where('user_id', $user->id)->count());
    }

    public function test_cim_staff_are_mapped_without_creating_new_hierarchy_records(): void
    {
        $this->seed([RoleSeeder::class, DepartmentSeeder::class, ApprovedMinistryStructureSeeder::class, UserSeeder::class]);
        $this->seed(CimStaffSeeder::class);

        $this->assertSame(15, Department::count());
        $this->assertSame(14, Division::count());
        $this->assertSame(48, OrganizationalUnit::count());
        $this->assertSame(296, Position::count());
        $this->assertSame(340, User::where('external_id', 'like', 'CIM:%')->count());

        $permanentSecretary = User::where('username', 'jkaggwa')->firstOrFail();
        $this->assertSame('Kedrace R.T. Turyagyenda', $permanentSecretary->full_name);
        $this->assertSame('14434', $permanentSecretary->employee_number);
        $this->assertSame(RoleEnum::Ps, $permanentSecretary->role);

        $commissioner = User::where('employee_number', '871458')->with('currentPositionAssignment.position')->firstOrFail();
        $this->assertSame('Duncans Mugumya', $commissioner->full_name);
        $this->assertSame('Commissioner – Physical Education and Sports', $commissioner->currentPositionAssignment?->position?->title);

        $inspector = User::where('employee_number', '147926')->with('currentPositionAssignment')->firstOrFail();
        $this->assertSame('Senior Inspector of Schools', $inspector->title);
        $this->assertSame('IC', $inspector->department?->code);
        $this->assertNull($inspector->currentPositionAssignment);

        $userCount = User::count();
        $appointmentCount = UserPosition::where('active', true)->count();
        $this->seed(CimStaffSeeder::class);
        $this->assertSame($userCount, User::count());
        $this->assertSame($appointmentCount, UserPosition::where('active', true)->count());
    }
}
