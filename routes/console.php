<?php

use App\Models\ImpersonationSession;
use App\Services\ImpersonationService;
use Illuminate\Support\Facades\Schedule;

// Overdue detection runs early each morning, Kampala time (PRD §22.3).
Schedule::command('ats:notify-overdue')->dailyAt('06:00')->timezone('Africa/Kampala');

Schedule::call(function () {
    ImpersonationSession::whereNull('ended_at')->where('expires_at', '<=', now())->each(
        fn ($support) => app(ImpersonationService::class)->close($support, 'Session timed out'),
    );
})->everyMinute()->name('Close expired support sessions');
