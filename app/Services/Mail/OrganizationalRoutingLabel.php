<?php

namespace App\Services\Mail;

use App\Models\OrganizationalUnit;
use App\Support\OfficialOrganizationalCodes;

class OrganizationalRoutingLabel
{
    public function for(?OrganizationalUnit $unit, string $fallback = 'Office not recorded'): string
    {
        if ($unit === null) {
            return $fallback;
        }

        $executiveOfficeCode = OfficialOrganizationalCodes::displayFor($unit->code, $unit->name);
        if ($executiveOfficeCode !== null) {
            return $executiveOfficeCode;
        }

        $departmentCode = strtoupper(trim((string) $unit->department?->code));
        if ($departmentCode !== '' && ($unit->type === 'department' || $this->isCommissionerOffice($unit))) {
            return "C/{$departmentCode}";
        }

        $unitCode = strtoupper(trim((string) $unit->code));

        return $unitCode !== '' ? $unitCode : $unit->name;
    }

    private function isCommissionerOffice(OrganizationalUnit $unit): bool
    {
        return strtolower(trim($unit->name)) === 'office of the commissioner';
    }
}
