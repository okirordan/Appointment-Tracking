<?php

namespace App\Http\Controllers\Dashboards;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\OfficeScheduleItem;
use App\Services\DashboardService;
use App\Services\SecretaryAuthorityService;
use App\Services\SecretaryOfficeScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SecretaryOfficeDashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboards,
        private SecretaryAuthorityService $authority,
        private SecretaryOfficeScope $officeScope,
    ) {}

    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()->role === Role::Secretary, 403);

        return Inertia::render('dashboards/secretary-office', $this->dashboards->secretaryOffice($request->user()));
    }

    public function storeSchedule(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === Role::Secretary, 403);
        $attachment = $this->authority->attachment($request->user());
        abort_if($attachment === null && $this->authority->supportedDepartmentId($request->user()) === null, 403);
        $data = $request->validate([
            'type' => ['required', Rule::in(['meeting', 'deadline', 'reminder'])],
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['required', 'date', 'after_or_equal:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        OfficeScheduleItem::create([
            ...$data,
            ...$this->officeScope->scheduleAttributes($request->user(), $attachment),
            'created_by_user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Office schedule item added.');
    }

    public function destroySchedule(Request $request, OfficeScheduleItem $scheduleItem): RedirectResponse
    {
        abort_unless($request->user()->role === Role::Secretary, 403);
        abort_unless($this->officeScope->scheduleItems($request->user())->whereKey($scheduleItem->id)->exists(), 403);
        $scheduleItem->delete();

        return back()->with('success', 'Office schedule item removed.');
    }
}
