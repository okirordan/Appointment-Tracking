<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ministry branding (PRD §12.20)
    |--------------------------------------------------------------------------
    */

    'ministry_full_name' => 'Ministry of Education and Sports',
    'ministry_short_name' => 'MoES',
    'system_title' => 'Assignment Tracking System',

    /*
    |--------------------------------------------------------------------------
    | Evidence uploads (PRD §12.11)
    |--------------------------------------------------------------------------
    */

    'evidence' => [
        'allowed_extensions' => ['pdf', 'docx', 'xlsx', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov', 'm4v'],
        'allowed_mimes' => [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'video/mp4',
            'video/webm',
            'video/quicktime',
            'video/x-m4v',
        ],
        'max_size_kb' => (int) env('ATS_EVIDENCE_MAX_KB', 102400),
        'max_items_per_update' => 5,
    ],

    'mail' => [
        'enabled' => (bool) env('ATS_MAIL_ENABLED', true),
        'allowed_extensions' => ['pdf', 'docx', 'xlsx', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov', 'm4v'],
        'max_size_kb' => (int) env('ATS_MAIL_MAX_KB', 102400),
        'max_items' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search (PRD §12.2)
    |--------------------------------------------------------------------------
    */

    'search' => [
        'min_chars' => 2,
        'recent_per_user' => 10,
        'results_per_page' => 20,
        'cache_fresh_seconds' => (int) env('ATS_SEARCH_CACHE_FRESH_SECONDS', 20),
        'cache_stale_seconds' => (int) env('ATS_SEARCH_CACHE_STALE_SECONDS', 90),
    ],

    'performance' => [
        'minimum_sample' => 5,
        'timezone' => 'Africa/Kampala',
    ],

    'imports' => [
        'max_size_kb' => 2 * 1024 * 1024,
        'chunk_size' => 500,
        'allowed_extensions' => ['csv', 'xlsx'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deadlines / notifications (PRD §22.3)
    |--------------------------------------------------------------------------
    */

    'upcoming_deadline_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Account lockout (PWD-007)
    |--------------------------------------------------------------------------
    */

    'lockout_after_failures' => (int) env('ATS_LOCKOUT_AFTER', 10),

    /*
    |--------------------------------------------------------------------------
    | Demo data purge (SET-004): disabled unless explicitly enabled for a
    | controlled migration. Never enable in production by default.
    |--------------------------------------------------------------------------
    */

    'allow_demo_purge' => (bool) env('ATS_ALLOW_DEMO_PURGE', false),
];
