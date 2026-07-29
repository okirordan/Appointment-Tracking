<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Progressive Web App
    |--------------------------------------------------------------------------
    |
    | When disabled the layout emits no manifest/PWA meta tags and the
    | frontend unregisters any previously installed service worker, so the
    | application keeps working as a plain web app. Colours default to the
    | ATS design tokens (--pri and --page in resources/css/app.css).
    |
    */

    'enabled' => (bool) env('PWA_ENABLED', true),

    'name' => env('PWA_NAME', 'Assignment Tracking System'),
    'short_name' => env('PWA_SHORT_NAME', 'ATS'),
    'description' => 'A digital system for assigning, tracking, updating, reviewing, and reporting organizational assignments.',

    'theme_color' => env('PWA_THEME_COLOR', '#155dfc'),
    'background_color' => env('PWA_BACKGROUND_COLOR', '#f7f6f2'),

    /*
    | Icon sizes generated in public/pwa/icons. Regenerate the PNGs from
    | public/images/moes-crest.jpg if the crest ever changes (see
    | docs/PWA_IMPLEMENTATION.md for the procedure).
    */

    'icon_sizes' => [72, 96, 128, 144, 152, 192, 384, 512],
    'maskable_sizes' => [192, 512],
];
