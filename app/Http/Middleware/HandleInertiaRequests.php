<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\NavigationService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(private NavigationService $navigation) {}

    /**
     * Determines the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Every user-derived prop is a closure so Inertia partial reloads
            // (searching, filtering, paginating) skip these queries outright.
            // The client already holds this data and never re-requests it, so
            // computing it on those visits was pure waste.
            'auth' => fn () => [
                'user' => $user === null ? null : $this->userPayload($user),
            ],
            'nav' => fn () => $user === null ? [] : $this->navigation->forUser($this->withRoles($user), $request),
            'notifications' => fn () => $user === null ? null : [
                'unread_count' => $user->appNotifications()->where('is_read', false)->count(),
                'items' => $user->appNotifications()
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get()
                    ->map(fn ($notification) => [
                        'id' => $notification->id,
                        'message' => $notification->message,
                        'is_read' => $notification->is_read,
                        'time_label' => $notification->created_at->format('d/m/Y H:i'),
                        'task_id' => $notification->related_task_id,
                    ]),
            ],
            'flash' => (function () use ($request) {
                $success = $request->session()->get('success');
                $error = $request->session()->get('error');
                $temp = $request->session()->get('temp_credential');

                return [
                    'success' => $success,
                    'error' => $error,
                    'status' => $request->session()->get('status'),
                    // One-time temporary password for the copyable admin dialog.
                    'temp_credential' => $temp,
                    // A per-delivery id: lets the client fire feedback exactly
                    // once per flash, immune to render timing and repeated
                    // identical messages.
                    'nonce' => ($success !== null || $error !== null || $temp !== null)
                        ? uniqid('', true)
                        : null,
                ];
            })(),
        ];
    }

    /**
     * Eager-load the relations every permission check below reads from.
     *
     * Spatie's getAllPermissions(), User::permissionRole() and the gate checks
     * inside NavigationService all prefer already-loaded relations, so loading
     * these once turns roughly five queries into two. loadMissing() is
     * idempotent, so the two callers pay for it once between them — and a
     * partial reload that resolves neither prop pays nothing at all.
     */
    private function withRoles(User $user): User
    {
        $user->loadMissing(['roles.permissions', 'permissions']);

        return $user;
    }

    /**
     * The signed-in user as the frontend layout consumes it.
     *
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $this->withRoles($user);

        return [
            'id' => $user->id,
            'username' => $user->username,
            'full_name' => $user->full_name,
            'first_name' => $user->firstName(),
            'initials' => $user->initials(),
            'title' => $user->title,
            'role' => $user->roleName(),
            'role_label' => $user->roleLabel(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'department' => $user->department === null ? null : [
                'id' => $user->department->id,
                'name' => $user->department->name,
                'code' => $user->department->code,
            ],
            'office_attachment' => $this->officeAttachment($user),
            'force_password_change' => $user->force_password_change,
            'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
        ];
    }

    /**
     * The supported-office summary for a secretary, or null for everyone else.
     *
     * @return array<string, mixed>|null
     */
    private function officeAttachment(User $user): ?array
    {
        $attachment = $user->currentSecretaryAttachment()
            ->with(['supervisor:id,full_name,title', 'organizationalUnit:id,name'])
            ->first();

        if ($attachment === null) {
            return null;
        }

        return [
            'id' => $attachment->id,
            'official_job_title' => $attachment->official_job_title,
            'supervisor_name' => $attachment->supervisor?->full_name,
            'supervisor_title' => $attachment->supervisor?->title,
            'office_name' => $attachment->organizationalUnit?->name,
            'delegated_permissions' => $attachment->delegated_permissions ?? [],
        ];
    }
}
