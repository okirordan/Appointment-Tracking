<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Validation\ValidationException;
use Throwable;

class DiagnosticRecorder
{
    private static bool $writing = false;

    public function record(string $category, string $action, array $details = [], string $severity = 'info', string $outcome = 'success'): void
    {
        if (self::$writing) {
            return;
        }
        self::$writing = true;
        try {
            app(AuditLogger::class)->log($category, $action, request()?->user(), metadata: $details, severity: $severity, outcome: $outcome);
        } catch (Throwable) {
            // Diagnostics must not break the original request or recursively log a database outage.
        } finally {
            self::$writing = false;
        }
    }

    public function message(MessageLogged $event): void
    {
        $error = in_array($event->level, ['error', 'critical', 'alert', 'emergency']);
        $this->record($error ? 'laravel' : 'system', (string) $event->message,
            ['message' => (string) $event->message, 'context' => $event->context], $event->level, $error ? 'failure' : 'success');
    }

    public function failed(Request $request, int $status, ?Throwable $exception = null): void
    {
        if ($request->attributes->get('ats_failure_logged')) {
            return;
        }
        $request->attributes->set('ats_failure_logged', true);
        $route = $request->route()?->getName() ?: 'unnamed route';
        $reason = match ($status) {
            401 => 'Authentication required', 403 => 'Permission denied', 419 => 'Session or CSRF verification failed',
            422 => 'Submitted information failed validation', 429 => 'Request rate limit exceeded',
            413 => 'Upload exceeds the permitted size', default => $status >= 500 ? 'Application error' : 'Request could not be completed',
        };
        $this->record('failed_action', $request->method().' '.$route, [
            'module' => explode('.', $route)[0], 'route' => $route, 'status' => $status, 'reason' => $reason,
            'resource_ids' => collect($request->route()?->parameters() ?? [])->map(fn ($value) => $value instanceof Model ? $value->getKey() : (is_numeric($value) ? $value : null))->filter()->all(),
            'exception_type' => $exception ? $exception::class : null,
            'validation_errors' => $exception instanceof ValidationException ? $exception->errors()
                : ($request->hasSession() ? $request->session()->get('errors')?->getBag('default')->getMessages() : null),
        ], $status >= 500 ? 'error' : 'warning', 'failure');
    }
}
