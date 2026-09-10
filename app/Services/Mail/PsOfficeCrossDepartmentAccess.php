<?php

namespace App\Services\Mail;

use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\OrganizationalScopeService;

class PsOfficeCrossDepartmentAccess
{
    public const PERMISSION = 'ps_office_cross_department_recording';

    public const FEATURE = 'ps_office_cross_department_recording';

    public function __construct(
        private MailFeatureSettings $features,
        private OrganizationalScopeService $organizations,
    ) {}

    public function allows(User $user): bool
    {
        return $this->features->enabled(self::FEATURE)
            && $user->can(self::PERMISSION)
            && $this->isPsOffice($this->organizations->primaryUnit($user));
    }

    public function psOffice(): OrganizationalUnit
    {
        return OrganizationalUnit::query()
            ->where('active', true)
            ->where(fn ($query) => $query
                ->where('code', 'OPS')
                ->orWhere('name', 'Office of the Permanent Secretary'))
            ->firstOrFail();
    }

    public function isPsOffice(?OrganizationalUnit $unit): bool
    {
        return $unit !== null
            && ($unit->code === 'OPS' || $unit->name === 'Office of the Permanent Secretary');
    }
}
