<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="system">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'ATS') }}</title>

        <link rel="icon" type="image/jpeg" href="/images/moes-crest.jpg">

        @if (config('pwa.enabled'))
            <link rel="manifest" href="{{ route('pwa.manifest') }}">
            <meta name="theme-color" content="{{ config('pwa.theme_color') }}">
            <meta name="application-name" content="{{ config('pwa.short_name') }}">
            <meta name="mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-status-bar-style" content="default">
            <meta name="apple-mobile-web-app-title" content="{{ config('pwa.short_name') }}">
            <link rel="apple-touch-icon" href="/pwa/icons/apple-touch-icon.png">
            {{-- Read by resources/js/pwa/register-service-worker.ts --}}
            <meta name="ats-pwa" content="enabled">
        @endif

        @routes(nonce: Illuminate\Support\Facades\Vite::cspNonce())
        @viteReactRefresh
        @vite(['resources/js/theme-init.ts', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
