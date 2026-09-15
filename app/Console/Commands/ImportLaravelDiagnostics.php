<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\LogRedactor;
use Illuminate\Console\Command;

class ImportLaravelDiagnostics extends Command
{
    protected $signature = 'ats:import-laravel-diagnostics';

    protected $description = 'Import recent redacted Laravel log headers and safe trace locations into administration diagnostics';

    public function handle(): int
    {
        $files = glob(storage_path('logs/laravel*.log')) ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $count = 0;
        $redactor = app(LogRedactor::class);
        foreach (array_slice($files, 0, 3) as $file) {
            $handle = fopen($file, 'rb');
            if (! $handle) {
                continue;
            }
            $size = filesize($file);
            if ($size > 5 * 1024 * 1024) {
                fseek($handle, -5 * 1024 * 1024, SEEK_END);
                fgets($handle);
            }
            $text = stream_get_contents($handle);
            fclose($handle);
            preg_match_all('/^\[([\d-]+ [\d:]+)\]\s+[^.\s]+\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY): ([^\r\n]*)/m', $text, $entries, PREG_SET_ORDER);
            foreach (array_slice($entries, -1000) as $entry) {
                $hash = hash('sha256', $entry[0]);
                if (AuditLog::where('metadata_json->legacy_fingerprint', $hash)->exists()) {
                    continue;
                }
                // Historical raw contexts and trace arguments are never copied.
                $message = preg_split('/\s+\{/', $entry[3], 2)[0];
                $level = strtolower($entry[2]);
                $error = in_array($level, ['error', 'critical', 'alert', 'emergency']);
                AuditLog::create([
                    'actor_name_snapshot' => 'System', 'category' => $error ? 'laravel' : 'system',
                    'action' => mb_substr($redactor->text($message), 0, 255), 'severity' => $level,
                    'outcome' => $error ? 'failure' : 'success', 'created_at' => $entry[1],
                    'metadata_json' => ['legacy_fingerprint' => $hash, 'message' => $redactor->text($message), 'source_file' => basename($file), 'note' => 'Imported historical header. Raw context and argument values are omitted. New errors include safe file and trace details.'],
                ]);
                $count++;
            }
        }
        $this->info("Imported {$count} historical log entries. Scanned the newest three files, up to 5 MB and 1,000 headers each.");

        return self::SUCCESS;
    }
}
