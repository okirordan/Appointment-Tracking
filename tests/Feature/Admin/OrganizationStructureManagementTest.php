<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\OrganizationalScopeService;
use Database\Seeders\OrganizationStructureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationStructureManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_uses_one_authoritative_structure_page_and_legacy_hierarchy_url_redirects(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $this->seed(OrganizationStructureSeeder::class);

        $this->actingAs($admin)->get(route('admin.organization-structure.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/hierarchy/index')
                ->has('entities')
                ->has('entityTypes', 7)
                ->has('summary')
                ->missing('positions')
                ->missing('departmentRecords'));

        $this->actingAs($admin)->get(route('admin.hierarchy.index'))
            ->assertRedirect('/admin/organization-structure');
    }

    public function test_admin_creates_any_supported_entity_under_a_selected_parent(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $root = OrganizationalUnit::create([
            'name' => 'Ministry of Education and Sports',
            'code' => 'MOES',
            'type' => 'ministry',
            'is_top_level' => true,
            'active' => true,
        ]);
        $head = User::factory()->role(Role::Commissioner)->create();
        $secretary = User::factory()->role(Role::Secretary)->create();

        $this->actingAs($admin)->post(route('admin.organization-structure.entities.store'), [
            'name' => 'Education Administration and Training',
            'code' => 'EAT',
            'type' => 'functional_area',
            'parent_id' => $root->id,
            'description' => 'Coordinates education administration and training departments.',
            'head_user_id' => $head->id,
            'secretary_user_id' => $secretary->id,
            'active' => true,
            'is_top_level' => false,
            'reason' => 'Approved organizational structure configuration.',
        ])->assertSessionHasNoErrors();

        $entity = OrganizationalUnit::where('code', 'EAT')->firstOrFail();
        $this->assertSame('functional_area', $entity->type);
        $this->assertSame($root->id, $entity->parent_id);
        $this->assertSame($head->id, $entity->head_user_id);
        $this->assertSame($secretary->id, $entity->secretary_user_id);
        $this->assertSame($entity->id, $secretary->fresh()->organizational_unit_id);
        $this->assertDatabaseHas('audit_logs', [
            'category' => 'organization_structure',
            'target_type' => 'OrganizationalUnit',
            'target_id' => $entity->id,
        ]);
    }

    public function test_duplicate_sibling_names_and_unintentional_orphans_are_rejected(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $parent = OrganizationalUnit::where('code', 'OPS')->firstOrFail();
        OrganizationalUnit::create([
            'name' => 'Division of Internal Audit',
            'code' => 'IA',
            'type' => 'division',
            'parent_id' => $parent->id,
            'active' => true,
        ]);

        $this->actingAs($admin)->post(route('admin.organization-structure.entities.store'), [
            'name' => 'Division of Internal Audit',
            'code' => 'IA-2',
            'type' => 'division',
            'parent_id' => $parent->id,
            'active' => true,
            'is_top_level' => false,
        ])->assertSessionHasErrors('name');

        $this->actingAs($admin)->post(route('admin.organization-structure.entities.store'), [
            'name' => 'Orphan Division',
            'code' => 'ORPHAN',
            'type' => 'division',
            'parent_id' => null,
            'active' => true,
            'is_top_level' => false,
        ])->assertSessionHasErrors('parent_id');
    }

    public function test_admin_can_move_an_entity_but_cannot_create_a_cycle(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $root = OrganizationalUnit::create([
            'name' => 'Ministry of Education and Sports',
            'code' => 'MOES',
            'type' => 'ministry',
            'is_top_level' => true,
            'active' => true,
        ]);
        $department = OrganizationalUnit::create([
            'name' => 'Department of Education Policy Analysis and Research',
            'code' => 'EPAR',
            'type' => 'department',
            'parent_id' => $root->id,
            'active' => true,
        ]);
        $division = OrganizationalUnit::create([
            'name' => 'Research and Innovation',
            'code' => 'RI',
            'type' => 'division',
            'parent_id' => $department->id,
            'active' => true,
        ]);

        $this->actingAs($admin)->patch(route('admin.organization-structure.entities.move', $department), [
            'parent_id' => $division->id,
            'is_top_level' => false,
            'reason' => 'Attempted invalid move.',
        ])->assertSessionHasErrors('parent_id');
        $this->assertSame($root->id, $department->fresh()->parent_id);

        $newParent = OrganizationalUnit::create([
            'name' => 'Education Administration and Training',
            'code' => 'EAT',
            'type' => 'functional_area',
            'parent_id' => $root->id,
            'active' => true,
        ]);
        $this->actingAs($admin)->patch(route('admin.organization-structure.entities.move', $department), [
            'parent_id' => $newParent->id,
            'is_top_level' => false,
            'reason' => 'Align with the approved structure.',
        ])->assertSessionHasNoErrors();

        $this->assertSame($newParent->id, $department->fresh()->parent_id);
        $audit = AuditLog::where('target_type', 'OrganizationalUnit')->where('target_id', $department->id)->latest('id')->firstOrFail();
        $this->assertSame($root->id, $audit->metadata_json['previous_parent_id']);
        $this->assertSame($newParent->id, $audit->metadata_json['new_parent_id']);
    }

    public function test_approved_structure_seeder_creates_the_authoritative_tree_without_inventing_regions(): void
    {
        $this->seed(OrganizationStructureSeeder::class);

        $ministry = OrganizationalUnit::where('code', 'MOES')->firstOrFail();
        $permanentSecretary = OrganizationalUnit::where('code', 'OPS')->firstOrFail();
        $higherEducation = OrganizationalUnit::where('name', 'Department of Higher Education')->firstOrFail();
        $teacherEducation = OrganizationalUnit::where('name', 'Division of Teacher Education, Training and Development')->firstOrFail();
        $internalAudit = OrganizationalUnit::where('name', 'Division of Internal Audit')->firstOrFail();
        $affiliated = OrganizationalUnit::where('name', 'Affiliated / External Bodies')->firstOrFail();

        $this->assertSame('ministry', $ministry->type);
        $this->assertSame($ministry->id, $permanentSecretary->parent_id);
        $this->assertSame('functional_area', $higherEducation->parent?->type);
        $this->assertSame($higherEducation->id, $teacherEducation->parent_id);
        $this->assertSame($permanentSecretary->id, $internalAudit->parent_id);
        $this->assertSame('affiliated_body', $affiliated->type);
        $this->assertSame(0, OrganizationalUnit::where('type', 'regional_office')->count());
        $this->assertSame(5, OrganizationalUnit::where('type', 'office')->where('parent_id', $ministry->id)->count());

        $count = OrganizationalUnit::count();
        $this->seed(OrganizationStructureSeeder::class);
        $this->assertSame($count, OrganizationalUnit::count());
    }

    public function test_affiliated_bodies_do_not_grant_internal_entity_scope(): void
    {
        $this->seed(OrganizationStructureSeeder::class);
        $external = OrganizationalUnit::where('code', 'EXT-01')->firstOrFail();
        $user = User::factory()->role(Role::Officer)->create([
            'organizational_unit_id' => $external->id,
            'department_id' => null,
            'division_id' => null,
        ]);

        $this->assertSame([], app(OrganizationalScopeService::class)->unitIds($user));
        $this->assertNull(app(OrganizationalScopeService::class)->primaryUnit($user));
    }

    public function test_internal_entities_cannot_move_into_external_or_inactive_branches(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $this->seed(OrganizationStructureSeeder::class);
        $internal = OrganizationalUnit::where('code', 'PDU')->firstOrFail();
        $external = OrganizationalUnit::where('code', 'EXTERNAL')->firstOrFail();

        $this->actingAs($admin)->patch(route('admin.organization-structure.entities.move', $internal), [
            'parent_id' => $external->id,
            'is_top_level' => false,
            'reason' => 'Invalid cross-boundary move.',
        ])->assertSessionHasErrors('parent_id');

        $external->update(['active' => false]);
        $this->actingAs($admin)->patch(route('admin.organization-structure.entities.move', $internal), [
            'parent_id' => $external->id,
            'is_top_level' => false,
            'reason' => 'Invalid inactive-parent move.',
        ])->assertSessionHasErrors('parent_id');
    }

    public function test_authoritative_structure_migration_is_reversible(): void
    {
        $migration = require database_path('migrations/2026_09_02_000001_expand_organizational_units_for_authoritative_structure.php');
        $head = User::factory()->role(Role::Commissioner)->create();
        $department = Department::factory()->create(['head_user_id' => $head->id]);
        $departmentEntity = OrganizationalUnit::create([
            'name' => $department->name,
            'code' => 'ROLLBACK-DEPT',
            'type' => 'department',
            'department_id' => $department->id,
            'is_top_level' => true,
            'active' => true,
        ]);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('organizational_units', 'secretary_user_id'));
        $this->assertFalse(Schema::hasColumn('departments', 'organizational_unit_id'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('organizational_units', 'secretary_user_id'));
        $this->assertTrue(Schema::hasColumn('departments', 'organizational_unit_id'));
        $this->assertSame($departmentEntity->id, Department::findOrFail($department->id)->organizational_unit_id);
        $this->assertSame($head->id, OrganizationalUnit::findOrFail($departmentEntity->id)->head_user_id);
    }
}
