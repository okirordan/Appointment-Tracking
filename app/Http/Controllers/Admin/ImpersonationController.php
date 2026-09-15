<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function store(Request $request, User $user, ImpersonationService $support)
    {
        $support->start($request, $user);

        return redirect()->route('home');
    }

    public function destroy(Request $request, ImpersonationService $support)
    {
        return redirect()->route($support->finish($request) ? 'admin.users.index' : 'login');
    }
}
