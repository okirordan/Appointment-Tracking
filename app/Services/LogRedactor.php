<?php

namespace App\Services;

use Throwable;

class LogRedactor
{
    public function clean(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[details omitted]';
        }
        if ($value instanceof Throwable) {
            return [
                'type' => $value::class,
                'message' => $this->text($value->getMessage()),
                'file' => $value->getFile(), 'line' => $value->getLine(),
                'trace' => array_map(fn ($frame) => array_intersect_key($frame, array_flip(['file', 'line', 'class', 'function'])), array_slice($value->getTrace(), 0, 40)),
            ];
        }
        if (is_array($value)) {
            $result = [];
            foreach (array_slice($value, 0, 100, true) as $key => $item) {
                if (is_bool($item) && in_array($key, ['password_changed', 'two_factor_enabled', 'password_configured'])) {
                    $result[$key] = $item;

                    continue;
                }
                $result[$key] = preg_match('/password|passwd|token|secret|session|cookie|authorization|credential|private.?key|api.?key|encryption.?key|recovery.?code|client.?key/i', (string) $key)
                    ? '[redacted]' : $this->clean($item, $depth + 1);
            }

            return $result;
        }
        if (is_string($value)) {
            return $this->text($value);
        }

        return is_scalar($value) || $value === null ? $value : '[object omitted]';
    }

    public function text(string $text): string
    {
        // Never expose SQL bindings, request bodies or authentication headers.
        $text = preg_replace('/\b(SQL:|bindings:|request body:).*$/is', '$1 [redacted]', $text);
        $text = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9+\/=._~-]+/i', '$1 [redacted]', $text);
        $text = preg_replace('/((?:password|passwd|token|secret|session|cookie|authorization|credential|api[_-]?key|private[_-]?key|encryption[_-]?key)[\w-]*["\x27]?\s*[:=]\s*)(?:\[redacted\]|"[^"\r\n]*"|\x27[^\x27\r\n]*\x27|[^\s,;&}\]]+)/i', '$1[redacted]', $text);
        $text = preg_replace('/-----BEGIN [^-]*PRIVATE KEY-----.*?-----END [^-]*PRIVATE KEY-----/s', '[redacted private key]', $text);
        $text = preg_replace('#(\w+://)[^\s/@:]+:[^\s/@]+@#', '$1[redacted]@', $text);
        foreach (['app.key', 'database.connections.mysql.password', 'database.connections.pgsql.password', 'mail.mailers.smtp.password', 'services.webpush.private_key'] as $key) {
            $secret = config($key);
            if (is_string($secret) && strlen($secret) >= 6) {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }

        return mb_substr($text, 0, 12000);
    }
}
