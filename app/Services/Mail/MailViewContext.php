<?php

namespace App\Services\Mail;

use Illuminate\Http\Request;

/** Presentation preferences only; these never affect access to mail records. */
class MailViewContext
{
    public static function mode(Request $request): string
    {
        return app(MailModePreferences::class)->mode($request);
    }

    public static function parameters(Request $request): array
    {
        return array_filter(array_intersect_key($request->query(), array_flip([
            'mode', 'section', 'q', 'status', 'priority', 'department_id', 'recipient',
            'assigned_to_user_id', 'financial_year', 'date_from', 'date_to', 'page', 'per_page', 'category',
        ])), fn ($value) => is_scalar($value));
    }
}
