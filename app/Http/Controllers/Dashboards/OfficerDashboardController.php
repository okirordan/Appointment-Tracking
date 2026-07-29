<?php

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OfficerDashboardController extends Controller
{
    public function __construct(private DashboardService $dashboards) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboards/officer', $this->dashboards->officer($request->user()));
    }
}
