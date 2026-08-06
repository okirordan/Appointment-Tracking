<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\SystemNotificationMail;
use App\Models\AuditLog;
use App\Models\MailRecord;
use App\Models\NotificationDelivery;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailNotificationConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_saves_encrypted_smtp_configuration_and_can_send_a_test_email(): void
    {
        Mail::fake();
        $admin = User::factory()->role(Role::Sysadmin)->create();

        $this->actingAs($admin)->put(route('admin.settings.email.update'), [
            'enabled' => true,
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'ats@example.test',
            'password' => 'smtp-secret-password',
            'from_address' => 'ats@example.test',
            'from_name' => 'ATS Notifications',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $encrypted = Setting::value('mail_smtp_password');
        $this->assertNotSame('smtp-secret-password', $encrypted);
        $this->assertSame('smtp-secret-password', Crypt::decryptString($encrypted));
        $this->assertTrue(AuditLog::query()
            ->where('action', 'Updated email notification configuration')
            ->whereJsonContains('metadata_json->password_changed', true)
            ->exists());

        $this->actingAs($admin)->post(route('admin.settings.email.test'), [
            'recipient' => 'registry@example.test',
        ])->assertRedirect()->assertSessionHasNoErrors();

        Mail::assertSent(SystemNotificationMail::class, fn (SystemNotificationMail $mail) => $mail->hasTo('registry@example.test') && $mail->heading === 'ATS email configuration test');
    }

    public function test_unassignment_sends_an_email_to_the_users_official_address(): void
    {
        Mail::fake();
        $this->configureEmail();
        $clerk = User::factory()->role(Role::Clerk)->create();
        $officer = User::factory()->role(Role::Officer)->create(['email' => 'officer@example.test']);
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);

        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'assigned_to_user_id' => $officer->id,
            'action_required' => true,
            'priority' => 'high',
        ])->assertSessionHasNoErrors();
        $task = Task::firstOrFail();
        Mail::fake();

        $this->actingAs($clerk)->post(route('tasks.workflow.unassign', $task), [
            'user_ids' => [$officer->id],
            'reason' => 'Assignment redirected to another officer.',
            'confirmed' => true,
        ])->assertSessionHasNoErrors();

        Mail::assertSent(SystemNotificationMail::class, fn (SystemNotificationMail $message) => $message->hasTo('officer@example.test')
            && str_contains(strtolower($message->heading), 'unassigned'));
        $this->assertTrue(NotificationDelivery::query()
            ->where('channel', 'email')
            ->where('status', 'delivered')
            ->exists());
    }

    private function configureEmail(): void
    {
        Setting::put('email_notifications_enabled', '1');
        Setting::put('mail_smtp_host', 'smtp.example.test');
        Setting::put('mail_smtp_port', '587');
        Setting::put('mail_smtp_encryption', 'tls');
        Setting::put('mail_from_address', 'ats@example.test');
        Setting::put('mail_from_name', 'ATS Notifications');
    }
}
