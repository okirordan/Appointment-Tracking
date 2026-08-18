<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\MailRecord;
use App\Models\Setting;
use App\Models\User;
use App\Services\Mail\MailFeatureSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CorrespondenceFeatureSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_correspondence_features_are_disabled_by_default_and_can_be_enabled_by_an_administrator(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $features = app(MailFeatureSettings::class);

        $this->assertTrue(collect($features->all())->every(fn (bool $enabled) => ! $enabled));
        $this->actingAs($admin)->get(route('admin.settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('mailFeatures', 9)
                ->where('mailFeatures', fn ($items) => collect($items)->every(fn ($item) => $item['enabled'] === false)));

        $values = collect($features->definitions())->mapWithKeys(fn ($label, $key) => [$key => $key === 'priority'])->all();
        $this->actingAs($admin)->put(route('admin.settings.mail-features.update'), ['features' => $values])
            ->assertSessionHasNoErrors();

        $this->assertSame('1', Setting::value('mail_feature_priority'));
        $this->assertTrue($features->enabled('priority'));
        $this->assertFalse($features->enabled('register_number'));
    }

    public function test_disabled_fields_are_ignored_and_receive_safe_defaults_on_capture(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();

        $this->actingAs($clerk)->post(route('mail.incoming.store'), [
            'register_number' => 'CUSTOM-001',
            'sender_name' => 'Sender',
            'recipient_name' => 'Permanent Secretary',
            'subject' => 'Default feature values',
            'received_date' => today()->toDateString(),
            'correspondence_reference' => 'REF-HIDDEN',
            'receipt_method' => 'courier',
            'confidentiality' => 'restricted',
            'registry_file_number' => 'FILE-HIDDEN',
            'priority' => 'urgent',
            'status' => 'received',
        ])->assertSessionHasNoErrors();

        $mail = MailRecord::query()->sole();
        $this->assertNotSame('CUSTOM-001', $mail->register_number);
        $this->assertNull($mail->correspondence_reference);
        $this->assertNull($mail->receipt_method);
        $this->assertSame('normal', $mail->confidentiality);
        $this->assertNull($mail->registry_file_number);
        $this->assertSame('medium', $mail->priority->value);
        $this->assertSame('registered', $mail->status->value);
    }
}
