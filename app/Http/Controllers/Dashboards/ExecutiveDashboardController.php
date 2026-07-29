<?php

namespace App\Http\Controllers\Dashboards;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExecutiveDashboardController extends Controller
{
    public function __construct(private DashboardService $dashboards) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboards/executive', [
            ...$this->dashboards->executive($request->user()),
            'canCreate' => $request->user()->can('create', Task::class),
            'canDrillDownDepartmentPerformance' => $request->user()->role === Role::Ps,
        ]);
    }
}
