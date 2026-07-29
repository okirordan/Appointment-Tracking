<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\SecretaryAttachmentService;
use Illuminate\Database\Seeder;

class SecretaryOfficeAttachmentSeeder extends Seeder
{
    public function run(): void
    {
        $gorreti = User::query()->where('employee_number', '14208')->first();
        $permanentSecretary = User::query()->where('role', Role::Ps->value)->where('active', true)->first();
        if ($gorreti === null || $permanentSecretary === null || $gorreti->currentSecretaryAttachment()->exists()) {
            return;
        }

        $office = OrganizationalUnit::withTrashed()->firstOrCreate(
            ['name' => 'Office of the Permanent Secretary'],
            [
                'type' => 'office',
                'code' => 'OPS',
                'active' => true,
            ],
        );
        if ($office->trashed() || ! $office->active) {
            $office->restore();
            $office->forceFill(['type' => 'office', 'active' => true])->save();
        }

        app(SecretaryAttachmentService::class)->assign(
            $gorreti,
            $permanentSecretary,
            $office,
            'Senior Personal Secretary to the Permanent Secretary',
            now(),
            null,
            false,
            [],
            null,
            'Initial approved attachment to the Office of the Permanent Secretary.',
        );
    }
}
