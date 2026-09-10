<?php

namespace App\Support;

final class OfficialOrganizationalCodes
{
    /** @var array<string, string> */
    public const MINISTERIAL_OFFICES = [
        'MES' => 'Office of the Minister of Education and Sports',
        'MSE/S' => 'Office of the Minister of State for Sports',
        'MSE/PE' => 'Office of the Minister of State for Primary Education',
        'MSE/HE' => 'Office of the Minister of State for Higher Education',
    ];

    /** @var array<string, string> */
    private const DISPLAY_BY_STORED_CODE = [
        'OPS' => 'PS',
        'PS' => 'PS',
        'OMES' => 'MES',
        'MES' => 'MES',
        'OSMS' => 'MSE/S',
        'MSE/S' => 'MSE/S',
        'OSMPE' => 'MSE/PE',
        'MSE/P' => 'MSE/PE',
        'MSE/PE' => 'MSE/PE',
        'OSMHE' => 'MSE/HE',
        'MSE/HE' => 'MSE/HE',
    ];

    /** @var array<string, string> */
    private const DISPLAY_BY_OFFICE_NAME = [
        'office of the permanent secretary' => 'PS',
        'office of the minister of education and sports' => 'MES',
        'office of the minister of education & sports' => 'MES',
        'office of the minister of state for sports' => 'MSE/S',
        'office of the state minister for sports' => 'MSE/S',
        'office of the minister of state for primary education' => 'MSE/PE',
        'office of the state minister for primary education' => 'MSE/PE',
        'office of the minister of state for higher education' => 'MSE/HE',
        'office of the state minister for higher education' => 'MSE/HE',
    ];

    public static function displayFor(?string $storedCode, ?string $officeName): ?string
    {
        $code = strtoupper(trim((string) $storedCode));
        if (isset(self::DISPLAY_BY_STORED_CODE[$code])) {
            return self::DISPLAY_BY_STORED_CODE[$code];
        }

        $name = strtolower(trim((string) $officeName));

        return self::DISPLAY_BY_OFFICE_NAME[$name] ?? null;
    }
}
