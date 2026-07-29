<?php

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentDashboardController extends Controller
{
    public function __construct(private DashboardService $dashboards) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboards/department', [
            ...$this->dashboards->department($request->user()),
            'departmentName' => $request->user()->department?->name ?? 'Department',
            'canCreate' => $request->user()->can('create', Task::class),
        ]);
    }
}
