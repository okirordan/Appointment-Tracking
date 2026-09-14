<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AnnotationTitle;
use App\Models\Department;
use App\Models\RecipientAlias;
use App\Models\User;
use App\Services\Mail\RecipientSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SharedTitleDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_shorthand_is_available_to_mail_title_pickers_and_can_be_disabled(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $secretary = User::factory()->role(Role::Secretary)->create();
        $department = Department::factory()->create(['name' => 'Test Records Department']);

        $this->actingAs($admin)->post(route('admin.recipient-aliases.store'), [
            'alias' => 'D/TEST', 'target_type' => 'department', 'target_id' => $department->id,
        ])->assertSessionHasNoErrors();

        $title = $this->actingAs($secretary)->getJson(route('annotation-titles.index', ['q' => 'D/TEST']))
            ->assertOk()->assertJsonCount(1, 'titles')->json('titles.0');
        $this->assertSame('Test Records Department', $title['full_title']);

        $alias = RecipientAlias::where('normalized_alias', 'dtest')->firstOrFail();
        $this->actingAs($admin)->post(route('admin.recipient-aliases.toggle', $alias))->assertSessionHasNoErrors();
        $this->actingAs($secretary)->getJson(route('annotation-titles.index', ['q' => 'D/TEST']))
            ->assertOk()->assertJsonCount(0, 'titles');
        $this->actingAs($secretary)->postJson(route('annotation-titles.store'), [
            'shorthand' => 'D/TEST', 'full_title' => 'Test Records Department',
        ])->assertUnprocessable();
    }

    public function test_secretary_title_is_visible_to_admin_and_reused_when_linking_an_alternative_code(): void
    {
        $secretary = User::factory()->role(Role::Secretary)->create();
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $department = Department::factory()->create(['name' => 'Test Records Department']);
        $created = $this->actingAs($secretary)->postJson(route('annotation-titles.store'), [
            'shorthand' => 'D/TEST', 'full_title' => $department->name,
        ])->assertCreated()->json('title');

        $this->actingAs($admin)->get(route('admin.recipient-aliases.index'))->assertInertia(fn (Assert $page) => $page
            ->component('admin/recipient-aliases/index')
            ->where('titles', fn ($titles) => collect($titles)->contains('id', $created['id'])));

        $this->actingAs($admin)->post(route('admin.recipient-aliases.store'), [
            'alias' => 'D/RECORDS', 'target_type' => 'department', 'target_id' => $department->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($created['id'], RecipientAlias::where('normalized_alias', 'drecords')->firstOrFail()->annotation_title_id);
        $this->actingAs($secretary)->getJson(route('annotation-titles.index', ['q' => 'D/RECORDS']))
            ->assertOk()->assertJsonPath('titles.0.id', $created['id']);
        $this->assertSame(1, AnnotationTitle::where('normalized_full_title', AnnotationTitle::normalize($department->name))->count());
    }

    public function test_admin_can_manage_shared_titles_without_giving_secretaries_administration_access(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $secretary = User::factory()->role(Role::Secretary)->create();
        $this->actingAs($admin)->post(route('admin.annotation-titles.store'), [
            'shorthand' => 'TEST/OFF', 'full_title' => 'Test Official Designation',
        ])->assertSessionHasNoErrors();
        $title = AnnotationTitle::where('normalized_shorthand', 'testoff')->firstOrFail();
        $this->actingAs($admin)->put(route('admin.annotation-titles.update', $title), [
            'shorthand' => 'TEST/NEW', 'full_title' => 'Updated Test Official Designation',
        ])->assertSessionHasNoErrors();
        $this->assertSame('TEST/NEW', $title->fresh()->shorthand);
        $this->actingAs($secretary)->post(route('admin.annotation-titles.toggle', $title))->assertForbidden();
        $this->actingAs($admin)->post(route('admin.annotation-titles.toggle', $title))->assertSessionHasNoErrors();
        $this->actingAs($secretary)->postJson(route('annotation-titles.store'), [
            'shorthand' => 'TEST/NEW', 'full_title' => 'Updated Test Official Designation',
        ])->assertUnprocessable();
        $this->actingAs($admin)->post(route('admin.annotation-titles.toggle', $title))->assertSessionHasNoErrors();
        $this->actingAs($secretary)->getJson(route('annotation-titles.index', ['q' => 'TEST/NEW']))
            ->assertOk()->assertJsonPath('titles.0.id', $title->id);
    }

    public function test_linked_canonical_title_search_finds_the_same_recipient_and_respects_deactivation(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $viewer = User::factory()->role(Role::Ps)->create();
        $officer = User::factory()->role(Role::Officer)->create();
        $this->actingAs($admin)->post(route('admin.recipient-aliases.store'), [
            'alias' => 'TEST/ROUTE', 'target_type' => 'officer', 'target_id' => $officer->id,
        ])->assertSessionHasNoErrors();
        $alias = RecipientAlias::where('normalized_alias', 'testroute')->firstOrFail();
        $title = $alias->annotationTitle;
        $this->actingAs($admin)->put(route('admin.annotation-titles.update', $title), [
            'shorthand' => 'TEST/CANONICAL', 'full_title' => 'Test Canonical Designation',
        ])->assertSessionHasNoErrors();
        $found = app(RecipientSearchService::class)->search($viewer, 'TEST/CANONICAL');
        $this->assertContains($officer->id, array_column($found, 'id'));

        $this->actingAs($admin)->post(route('admin.annotation-titles.toggle', $title))->assertSessionHasNoErrors();
        $this->assertSame([], app(RecipientSearchService::class)->search($viewer, 'TEST/ROUTE'));
        $this->actingAs($admin)->post(route('admin.recipient-aliases.toggle', $alias))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.recipient-aliases.toggle', $alias))->assertSessionHasNoErrors();
        $this->assertSame($title->id, $alias->fresh()->annotation_title_id);
        $this->assertFalse($title->fresh()->active, 'Editing an alias must not undo an explicit title deactivation.');
        $this->actingAs($admin)->post(route('admin.annotation-titles.toggle', $title))->assertSessionHasNoErrors();
        $this->assertTrue($title->fresh()->active);
    }

    public function test_alternative_code_cannot_be_created_as_an_unrelated_shared_title(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $secretary = User::factory()->role(Role::Secretary)->create();
        $department = Department::factory()->create(['name' => 'Test Alternative Department']);
        $title = AnnotationTitle::create(['shorthand' => 'TEST/CANON', 'full_title' => $department->name, 'active' => true]);
        $this->actingAs($admin)->post(route('admin.recipient-aliases.store'), [
            'alias' => 'TEST/ALT', 'target_type' => 'department', 'target_id' => $department->id,
        ])->assertSessionHasNoErrors();
        $this->actingAs($secretary)->postJson(route('annotation-titles.store'), [
            'shorthand' => 'TEST/ALT', 'full_title' => 'Unrelated department name',
        ])->assertOk()->assertJsonPath('title.id', $title->id)->assertJsonPath('existing', true);
        $this->assertDatabaseMissing('annotation_titles', ['normalized_shorthand' => 'testalt']);
    }

    public function test_same_alternative_code_for_two_targets_keeps_one_title(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $first = Department::factory()->create(['name' => 'Test Shared Department']);
        $second = Department::factory()->create(['name' => 'Other Test Shared Department']);
        $title = AnnotationTitle::create(['shorthand' => 'TEST/CANON', 'full_title' => $first->name, 'active' => true]);
        foreach ([$first, $second] as $department) {
            $this->actingAs($admin)->post(route('admin.recipient-aliases.store'), [
                'alias' => 'TEST/ALT', 'target_type' => 'department', 'target_id' => $department->id,
            ])->assertSessionHasNoErrors();
        }
        $this->assertSame([$title->id], RecipientAlias::where('normalized_alias', 'testalt')->pluck('annotation_title_id')->unique()->values()->all());
    }

    public function test_backfill_keeps_existing_title_ids_and_links_colliding_codes_without_rewriting_labels(): void
    {
        $migration = require database_path('migrations/2026_09_11_000001_link_recipient_aliases_to_shared_titles.php');
        $migration->down();
        $first = Department::factory()->create(['name' => 'First Test Department']);
        $second = Department::factory()->create(['name' => 'Second Test Department']);
        $title = AnnotationTitle::create(['shorthand' => 'TEST/SAME', 'full_title' => 'Existing Official Designation', 'active' => true]);
        $aliases = collect([$first, $second])->map(fn ($department) => RecipientAlias::create([
            'alias' => 'TEST/SAME', 'target_type' => Department::class, 'target_id' => $department->id, 'active' => true,
        ]));
        $migration->up();

        foreach ($aliases as $alias) {
            $this->assertSame($title->id, $alias->fresh()->annotation_title_id);
        }
        $this->assertSame('Existing Official Designation', $title->fresh()->full_title);
        $this->assertSame(1, AnnotationTitle::where('normalized_shorthand', 'testsame')->count());
    }
}
