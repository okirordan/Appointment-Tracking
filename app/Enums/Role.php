<?php

namespace App\Enums;

enum Role: string
{
    case Sysadmin = 'sysadmin';
    case Ps = 'ps';
    case Clerk = 'clerk';
    case Commissioner = 'commissioner';
    case Secretary = 'secretary';
    case Officer = 'officer';

    public function label(): string
    {
        return match ($this) {
            self::Sysadmin => 'System Administrator',
            self::Ps => 'Permanent Secretary',
            self::Clerk => 'Registry Clerk',
            self::Commissioner => 'Commissioner',
            self::Secretary => 'Secretary',
            self::Officer => 'Officer',
        };
    }

    /** Route name of the role's default landing dashboard (PRD §9, master prompt §4.1). */
    public function defaultDashboardRoute(): string
    {
        return match ($this) {
            self::Sysadmin => 'admin.dashboard',
            self::Ps => 'exec.dashboard',
            self::Clerk => 'home',
            self::Commissioner => 'dept.dashboard',
            self::Secretary => 'secretary.dashboard',
            self::Officer => 'officer.dashboard',
        };
    }

    /** @return array<string> */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }
}
