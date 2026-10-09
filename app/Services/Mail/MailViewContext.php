<?php

namespace App\Services\Mail;

use App\Enums\Role;
use Illuminate\Http\Request;

/** Presentation preferences only; these never affect access to mail records. */
class MailViewContext
{
    public static function mode(Request $request): string
    {
        if ($request->user() === null) {
            return 'full';
        }
        $key = 'mail_mode.'.$request->user()->id;
        $mode = $request->query('mode');
        if (in_array($mode, ['basic', 'full'], true)) {
            $request->session()->put($key, $mode);
        }

        return $request->session()->get($key, $request->user()->role === Role::Secretary ? 'basic' : 'full');
    }

    public static function parameters(Request $request): array
    {
        return array_filter(array_intersect_key($request->query(), array_flip([
            'mode', 'section', 'q', 'status', 'priority', 'department_id', 'recipient',
            'assigned_to_user_id', 'financial_year', 'date_from', 'date_to', 'page', 'category',
        ])), fn ($value) => is_scalar($value));
    }
}
