<?php

namespace App\Enums;

enum OrganizationalUnitType: string
{
    case Ministry = 'ministry';
    case Office = 'office';
    case FunctionalArea = 'functional_area';
    case Department = 'department';
    case Division = 'division';
    case Section = 'section';
    case Unit = 'unit';
    case RegionalOffice = 'regional_office';
    case AffiliatedBody = 'affiliated_body';

    public function label(): string
    {
        return match ($this) {
            self::Ministry => 'Ministry',
            self::Office => 'Office',
            self::FunctionalArea => 'Functional Area',
            self::Department => 'Department',
            self::Division => 'Division',
            self::Section => 'Section',
            self::Unit => 'Unit',
            self::RegionalOffice => 'Regional Office',
            self::AffiliatedBody => 'Affiliated / External Body',
        };
    }

    /** @return list<self> */
    public static function selectable(): array
    {
        return [
            self::Office,
            self::FunctionalArea,
            self::Department,
            self::Division,
            self::Section,
            self::Unit,
            self::RegionalOffice,
        ];
    }

    public function grantsInternalAccess(): bool
    {
        return $this !== self::AffiliatedBody;
    }
}
