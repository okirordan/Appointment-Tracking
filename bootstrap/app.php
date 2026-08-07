<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireCapability;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\RequireWorkMode;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'capability' => RequireCapability::class,
            'password.change' => RequirePasswordChange::class,
            'work-mode' => RequireWorkMode::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Render clean, in-app error pages instead of raw framework screens.
        // Full stack traces stay available to developers only (APP_DEBUG),
        // never to normal users (PRD §17, §23).
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();

            // A stale CSRF token / expired session surfaces as a friendly
            // notice on the previous page rather than a dead 419 screen.
            if ($status === 419) {
                return back()->with('error', 'Your session expired. Please try again.');
            }

            // 422 stays as-is: Inertia turns it into field-level validation
            // errors on the originating form. Every other error status is
            // shown as the clean in-app error page — no raw framework screen
            // ever reaches a normal user. Developers still get full traces
            // while APP_DEBUG is on.
            if (! config('app.debug') && $status >= 400 && $status !== 422) {
                // Deliberate abort(403, ...) messages (e.g. "You do not have
                // permission to view this correspondence.") are written for
                // end users and may be shown. Anything else — 404s carry
                // internal model names, 500s carry traces — falls back to the
                // generic copy so internals are never exposed.
                $message = $exception instanceof HttpExceptionInterface && $status === 403
                    ? $exception->getMessage()
                    : null;

                return Inertia::render('errors/error', [
                    'status' => $status,
                    'message' => $message !== '' ? $message : null,
                ])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
