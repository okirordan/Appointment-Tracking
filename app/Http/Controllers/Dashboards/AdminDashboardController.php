<?php

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function __construct(private DashboardService $dashboards) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboards/admin', $this->dashboards->admin(
            $request->user(),
            $request->integer('activity_page', 1),
            $request->integer('department_page', 1),
        ));
    }
}
