<?php

namespace App\Services\Mail;

use App\Models\OrganizationalUnit;
use App\Support\OfficialOrganizationalCodes;

class OrganizationalRoutingLabel
{
    /** Expand generic head offices without replacing a specific historical label. */
    public function headLabel(?OrganizationalUnit $unit, ?string $snapshot = null): ?string
    {
        $name = trim((string) ($snapshot ?: $unit?->name));
        if ($unit === null || $name === '') {
            return $name !== '' ? $name : null;
        }
        if (! preg_match('/^Office of (?:the )?(.+)$/iu', $name, $match)) {
            return $name;
        }
        $department = $unit->department ?? $unit->parent?->department;
        $context = trim((string) ($department?->code ?: $department?->name));
        if ($context === '') {
            return $name;
        }
        $title = trim($match[1]);
        if (str_contains(mb_strtolower($title), mb_strtolower($context))) {
            return $title;
        }

        return $title.' '.$context;
    }

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
