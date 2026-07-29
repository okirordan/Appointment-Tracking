<?php

use Illuminate\Support\Facades\Schedule;

// Overdue detection runs early each morning, Kampala time (PRD §22.3).
Schedule::command('ats:notify-overdue')->dailyAt('06:00')->timezone('Africa/Kampala');
