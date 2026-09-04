<?php

namespace App\Support;

use Illuminate\Support\Str;

final class TemporaryPassword
{
    public static function generate(): string
    {
        return Str::random(20).'aA1!';
    }
}
