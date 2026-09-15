<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

class ManageSuperAdmin extends Command
{
    protected $signature = 'ats:super-admin {username} {--revoke}';

    protected $description = 'Grant or revoke the reserved Super Admin support role for an existing administrator';

    public function handle(): int
    {
        $user = User::where('username', $this->argument('username'))->first();
        if (! $user || $user->role !== Role::Sysadmin || ! $user->mayAuthenticate()) {
            $this->error('An active System Administrator account is required.');

            return self::FAILURE;
        }
        $this->option('revoke') ? $user->removeRole('super_admin') : $user->assignRole('super_admin');
        app(AuditLogger::class)->log('security', $this->option('revoke') ? 'Revoked Super Admin role from server console' : 'Granted Super Admin role from server console', targetType: 'User', targetId: $user->id, metadata: ['username' => $user->username]);
        $this->info('Super Admin access updated for '.$user->full_name.'.');

        return self::SUCCESS;
    }
}
