<?php

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function __construct(private DashboardService $dashboards) {}

    public function __invoke(): Response
    {
        return Inertia::render('dashboards/admin', $this->dashboards->admin());
    }
}
