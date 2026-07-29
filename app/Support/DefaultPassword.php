<?php

namespace App\Support;

/**
 * The organisation's default credential for newly created, imported, or
 * administratively reset accounts (PWD-008): "Changeme@{currentYear}".
 *
 * The year is always derived from the system clock — never hard-coded —
 * and every account that receives this password is forced to change it at
 * first successful login. The value itself is never written to logs,
 * audit metadata, URLs, or API payloads.
 */
class DefaultPassword
{
    public static function value(): string
    {
        return 'Changeme@'.now()->year;
    }
}
