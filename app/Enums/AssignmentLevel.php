<?php

namespace App\Enums;

enum AssignmentLevel: string
{
    case Ps = 'ps';
    case Department = 'department';

    public function label(): string
    {
        return match ($this) {
            self::Ps => 'PS Level',
            self::Department => 'Department Level',
        };
    }
}
