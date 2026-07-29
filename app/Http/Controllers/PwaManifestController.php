<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Serves the web app manifest from config/pwa.php so branding and colours
 * stay configurable through environment variables instead of a hand-edited
 * static file. Public: browsers fetch it without credentials.
 */
class PwaManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $icons = [];

        foreach (config('pwa.icon_sizes') as $size) {
            $icons[] = [
                'src' => "/pwa/icons/icon-{$size}x{$size}.png",
                'sizes' => "{$size}x{$size}",
                'type' => 'image/png',
                'purpose' => 'any',
            ];
        }

        foreach (config('pwa.maskable_sizes') as $size) {
            $icons[] = [
                'src' => "/pwa/icons/icon-maskable-{$size}x{$size}.png",
                'sizes' => "{$size}x{$size}",
                'type' => 'image/png',
                'purpose' => 'maskable',
            ];
        }

        return response()->json([
            'id' => '/',
            'name' => config('pwa.name'),
            'short_name' => config('pwa.short_name'),
            'description' => config('pwa.description'),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            // Prefer the Window Controls Overlay on supporting desktop
            // browsers (Chromium on Windows/macOS/Linux): app content
            // extends into the title-bar area while the OS keeps its native
            // minimize/maximize/close buttons. Everything else falls back
            // to the plain standalone window with a theme-coloured title bar.
            'display_override' => ['window-controls-overlay', 'standalone'],
            'orientation' => 'any',
            'background_color' => config('pwa.background_color'),
            'theme_color' => config('pwa.theme_color'),
            'icons' => $icons,
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=3600',
        ], JSON_UNESCAPED_SLASHES);
    }
}
