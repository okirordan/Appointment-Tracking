<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\CorrespondenceForward;
use App\Models\CorrespondenceRecipient;
use App\Models\Department;
use App\Models\Division;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\RecipientAlias;
use App\Models\Role as PermissionRole;
use App\Models\User;
use App\Models\UserPosition;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RecipientSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
    }

    public function test_search_matches_names_titles_hierarchy_staff_fields_and_official_shorthand(): void
    {
        [$ps, $mail, $commissioner, $department, $unit, $position] = $this->directoryFixture();

        RecipientAlias::create(['alias' => 'PS/ES', 'target_type' => User::class, 'target_id' => $ps->id, 'active' => true]);
        RecipientAlias::create(['alias' => 'C/HRM', 'target_type' => Position::class, 'target_id' => $position->id, 'active' => true]);
        RecipientAlias::create(['alias' => 'HRM', 'target_type' => Department::class, 'target_id' => $department->id, 'active' => true]);

        foreach (['Sarah Namusoke', 'Namusoke', 'sarah', 'Commissioner Human Resource Management', 'Human Resource Management', 'HRM Directorate', 'Office of the Commissioner', 'snamusoke', 'MOES-4401'] as $query) {
            $this->actingAs($ps)->getJson(route('mail.recipient-search', ['mail' => $mail, 'q' => $query]))
                ->assertOk()
                ->assertJsonPath('recipients.0.id', $commissioner->id);
        }
        $this->actingAs($ps)->getJson(route('mail.recipient-search', ['mail' => $mail, 'q' => 'Sarah Namusoke']))
            ->assertOk()->assertJsonPath('recipients.0.shorthand_code', 'C/HRM');

        $this->actingAs($ps)->getJson(route('mail.recipient-search', ['mail' => $mail, 'q' => 'Permanent Secretary']))
            ->assertOk()->assertJsonFragment(['id' => $ps->id, 'name' => $ps->full_name]);

        foreach (['PS/ES', 'ps es', 'Ps-Es'] as $query) {
            $this->actingAs($ps)->getJson(route('mail.recipient-search', ['mail' => $mail, 'q' => $query]))
                ->assertOk()
                ->assertJsonPath('recipients.0.id', $ps->id)
                ->assertJsonPath('recipients.0.shorthand_code', 'PS/ES');
        }

        foreach (['C/HRM', 'c hrm', 'C-HRM'] as $query) {
            $this->actingAs($ps)->getJson(route('mail.recipient-search', ['mail' => $mail, 'q' => $query]))
                ->assertOk()
                ->assertJsonPath('recipients.0.id', $commissioner->id)
                ->assertJsonPath('recipients.0.recipient_type', 'position')
                ->assertJsonPath('recipients.0.department', $department->name)
                ->assertJsonPath('recipients.0.context', $unit->name)
                ->assertJsonPath('recipients.0.shorthand_code', 'C/HRM');
        }
    }

    public function test_position_alias_resolves_only_to_the_current_available_office_holder_without_duplicates(): void
    {
        [$ps, $mail, $commissioner, , , $position] = $this->directoryFixture();
        RecipientAlias::create(['alias' => 'C/HRM', 'target_type' => Position::class, 'target_id' => $position->id, 'active' => true]);
        RecipientAlias::create(['alias' => 'Commissioner HR', 'target_type' => User::class, 'target_id' => $commissioner->id, 'active' => true]);

        $oldHolder = User::factory()->role(Role::Commissioner)->create(['full_name' => 'Former HR Commissioner', 'department_id' => $commissioner->department_id]);
        UserPosition::create(['user_id' => $oldHolder->id, 'position_id' => $position->id, 'is_primary' => true, 'active' => false, 'ends_at' => now()->subDay()]);

        $response = $this->actingAs($ps)->getJson(route('mail.recipient-search', ['mail' => $mail, 'q' => 'C/HRM']))->assertOk();
        $this->assertSame([$commissioner->id], collect($response->json('recipients'))->pluck('id')->all());
    }

    public function test_commissioner_search_is_limited_to_authorised_departments_and_excludes_unavailable_users(): void
    {
        [$ps, $mail, $inside, $department] = $this->directoryFixture();
        $outsideDepartment = Department::factory()->create(['name' => 'Department of Libraries', 'code' => 'LIB']);
        $outside = User::factory()->role(Role::Officer)->create(['full_name' => 'Shared Search Name Outside', 'department_id' => $outsideDepartment->id]);
        $inside->update(['full_name' => 'Shared Search Name Inside']);
        User::factory()->role(Role::Officer)->create(['full_name' => 'Shared Search Name Locked', 'department_id' => $department->id, 'locked' => true]);
        User::factory()->role(Role::Officer)->create(['full_name' => 'Shared Search Name Inactive', 'department_id' => $department->id, 'active' => false]);
        $deleted = User::factory()->role(Role::Officer)->create(['full_name' => 'Shared Search Name Deleted', 'department_id' => $department->id]);
        $deleted->delete();

        $commissionerActor = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
        $commissionerActor->givePermissionTo('mail.assign');
        $department->update(['head_user_id' => $commissionerActor->id, 'head_name' => $commissionerActor->full_name]);

        $response = $this->actingAs($commissionerActor)->getJson(route('mail.recipient-search', ['mail' => $mail, 'q' => 'Shared Search Name']))->assertOk();
        $this->assertEqualsCanonicalizing(
            [$inside->id],
            collect($response->json('recipients'))->where('assignment_target_type', 'individual')->pluck('id')->all(),
        );

        $this->actingAs($ps)->getJson(route('mail.recipient-search', ['mail' => $mail, 'q' => 'No Such Recipient']))
            ->assertOk()->assertExactJson(['recipients' => []]);
    }

    public function test_backend_rejects_recipients_outside_the_commissioners_authorised_department(): void
    {
        [, $mail, $inside, $department] = $this->directoryFixture();
        $actor = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
        $actor->givePermissionTo('mail.assign');
        $department->update(['head_user_id' => $actor->id, 'head_name' => $actor->full_name]);
        $outsideDepartment = Department::factory()->create();
        $outside = User::factory()->role(Role::Officer)->create(['department_id' => $outsideDepartment->id]);
        $inactive = User::factory()->role(Role::Officer)->create(['department_id' => $department->id, 'active' => false]);

        $this->actingAs($actor)->post(route('mail.assign', $mail), [
            'department_id' => $department->id,
            'assigned_to_user_id' => $inactive->id,
            'priority' => 'medium',
        ])->assertSessionHasErrors('assigned_to_user_id');

        $this->actingAs($actor)->post(route('mail.assign', $mail), [
            'department_id' => $department->id,
            'assigned_to_user_id' => 999999,
            'priority' => 'medium',
        ])->assertSessionHasErrors('assigned_to_user_id');
        $this->actingAs($actor)->post(route('mail.assign', $mail), [
            'target_type' => 'individual',
            'department_id' => $outsideDepartment->id,
            'assigned_to_user_id' => $outside->id,
            'priority' => 'medium',
        ])->assertSessionHasErrors('assigned_to_user_ids');
        $this->assertNull($mail->refresh()->task_id);
    }

    public function test_mail_capture_party_search_uses_the_active_staff_directory(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $staff = User::factory()->role(Role::Officer)->create([
            'full_name' => 'Lydia Namukasa',
            'employee_number' => 'MOES-7788',
            'title' => 'Library Officer',
        ]);
        User::factory()->role(Role::Officer)->create(['full_name' => 'Lydia Inactive', 'active' => false]);

        $this->actingAs($clerk)->getJson(route('mail.party-search', ['q' => 'Lydia']))
            ->assertOk()
            ->assertJsonCount(1, 'recipients')
            ->assertJsonPath('recipients.0.id', $staff->id)
            ->assertJsonPath('recipients.0.assignment_target_type', 'individual')
            ->assertJsonPath('recipients.0.staff_id', 'MOES-7788');
    }

    public function test_administrator_can_manage_aliases_and_view_their_audit_history(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create(['full_name' => 'System Administrator']);
        $department = Department::factory()->create(['name' => 'Human Resource Management', 'code' => 'HRM']);

        $this->actingAs($admin)->post(route('admin.recipient-aliases.store'), [
            'alias' => 'C/HRM',
            'target_type' => 'department',
            'target_id' => $department->id,
        ])->assertSessionHasNoErrors();

        $alias = RecipientAlias::firstOrFail();
        $this->assertSame('chrm', $alias->normalized_alias);
        $this->assertDatabaseHas('audit_logs', ['target_type' => 'RecipientAlias', 'target_id' => $alias->id, 'actor_user_id' => $admin->id]);

        $this->actingAs($admin)->put(route('admin.recipient-aliases.update', $alias), [
            'alias' => 'C-HRM',
            'target_type' => 'department',
            'target_id' => $department->id,
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.recipient-aliases.toggle', $alias))->assertSessionHasNoErrors();

        $this->assertFalse($alias->refresh()->active);
        $this->assertSame(3, AuditLog::where('target_type', 'RecipientAlias')->where('target_id', $alias->id)->count());
        $this->actingAs($admin)->get(route('admin.recipient-aliases.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/recipient-aliases/index')
                ->where('aliases.0.alias', 'C-HRM')
                ->where('aliases.0.active', false)
                ->has('aliases.0.history', 3));
    }

    /** @return array{User, MailRecord, User, Department, OrganizationalUnit, Position} */
    private function directoryFixture(): array
    {
        $department = Department::factory()->create(['name' => 'Department of Human Resource Management', 'code' => 'HRM']);
        $division = Division::create(['department_id' => $department->id, 'name' => 'HRM Directorate', 'code' => 'HRMD', 'active' => true]);
        $unit = OrganizationalUnit::create(['department_id' => $department->id, 'division_id' => $division->id, 'type' => 'office', 'name' => 'Office of the Commissioner', 'code' => 'OCHRM', 'active' => true]);
        $role = PermissionRole::where('name', Role::Commissioner->value)->firstOrFail();
        $position = Position::create(['organizational_unit_id' => $unit->id, 'role_id' => $role->id, 'title' => 'Commissioner – Human Resource Management', 'hierarchy_level' => 20, 'active' => true]);
        $commissioner = User::factory()->role(Role::Commissioner)->create([
            'full_name' => 'Sarah Namusoke',
            'username' => 'snamusoke',
            'employee_number' => 'MOES-4401',
            'title' => 'Commissioner, Human Resource Management',
            'department_id' => $department->id,
            'division_id' => $division->id,
        ]);
        UserPosition::create(['user_id' => $commissioner->id, 'position_id' => $position->id, 'is_primary' => true, 'active' => true, 'starts_at' => now()->subDay()]);
        $department->update(['head_user_id' => $commissioner->id, 'head_name' => $commissioner->full_name]);
        $ps = User::factory()->role(Role::Ps)->create(['full_name' => 'Kedrace Turyagyenda', 'title' => 'Permanent Secretary']);
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'department_id' => $department->id,
            'organizational_unit_id' => $unit->id,
            'office_supervisor_user_id' => $commissioner->id,
        ]);
        $forward = CorrespondenceForward::create([
            'correspondence_id' => $mail->correspondence_id,
            'forwarded_by_user_id' => $commissioner->id,
            'from_organizational_unit_id' => $unit->id,
            'instructions' => 'Shared with the Permanent Secretary for recipient selection.',
            'status' => 'sent',
            'forwarded_at' => now(),
        ]);
        CorrespondenceRecipient::create([
            'correspondence_id' => $mail->correspondence_id,
            'correspondence_forward_id' => $forward->id,
            'recipient_type' => 'cc',
            'purpose' => 'information',
            'target_type' => 'individual',
            'user_id' => $ps->id,
            'recipient_name_snapshot' => $ps->full_name,
            'active' => true,
            'added_by_user_id' => $commissioner->id,
            'added_at' => now(),
        ]);

        return [$ps, $mail, $commissioner, $department, $unit, $position];
    }
}
