<?php

namespace App\Http\Middleware;

use App\Services\DiagnosticRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RecordRequestActivity
{
    public function handle(Request $request, Closure $next)
    {
        $recorder = app(DiagnosticRecorder::class);
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $status = $exception instanceof ValidationException ? 422
                : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500);
            $recorder->failed($request, $status, $exception);
            throw $exception;
        }
        if ($response->getStatusCode() >= 400) {
            $recorder->failed($request, $response->getStatusCode());
        } elseif (! $request->isMethodSafe() && $request->session()->has('errors')) {
            $recorder->failed($request, 422);
        } elseif ($request->user() && $request->isMethod('GET') && ! $request->headers->has('X-Inertia-Partial-Data')
            && $request->routeIs('home', '*.dashboard', '*.index', '*.show') && $response->getStatusCode() === 200) {
            $recorder->record('page_access', 'Viewed '.$request->route()->getName(), ['route' => $request->route()->getName()]);
        }

        return $response;
    }
}
