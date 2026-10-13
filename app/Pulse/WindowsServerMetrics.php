<?php

namespace App\Pulse;

use RuntimeException;

/** Pulse 1.x uses WMIC on Windows, which is absent from current Windows hosts. */
class WindowsServerMetrics
{
    private static int $readAt = 0;

    /** @var array{cpu: int, total: int, used: int}|null */
    private static ?array $reading = null;

    public static function cpu(): int
    {
        return self::snapshot()['cpu'];
    }

    /** @return array{total: int, used: int} */
    public static function memory(): array
    {
        $reading = self::snapshot();

        return ['total' => $reading['total'], 'used' => $reading['used']];
    }

    /** @return array{cpu: int, total: int, used: int} */
    private static function snapshot(): array
    {
        if (self::$reading !== null && time() - self::$readAt < 10) {
            return self::$reading;
        }

        $command = 'powershell.exe -NoProfile -NonInteractive -Command '
            .'"$ErrorActionPreference = \'Stop\'; '
            .'$cpu = (Get-CimInstance Win32_Processor | Measure-Object LoadPercentage -Average).Average; '
            .'$os = Get-CimInstance Win32_OperatingSystem; '
            .'\'{0},{1},{2}\' -f $cpu, $os.TotalVisibleMemorySize, $os.FreePhysicalMemory"';
        $output = trim((string) shell_exec($command));

        if (! preg_match('/^(\d+(?:\.\d+)?),(\d+),(\d+)$/', $output, $matches)) {
            throw new RuntimeException('Windows host resource counters are unavailable.');
        }

        $total = (int) round((int) $matches[2] / 1024);
        $free = (int) round((int) $matches[3] / 1024);
        if ($total <= 0 || $free > $total) {
            throw new RuntimeException('Windows host resource counters returned invalid values.');
        }

        self::$readAt = time();

        return self::$reading = [
            'cpu' => min(100, max(0, (int) round((float) $matches[1]))),
            'total' => $total,
            'used' => $total - $free,
        ];
    }
}
