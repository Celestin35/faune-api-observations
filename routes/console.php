<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('biodiversity:sync-due-monitoring')->everyMinute()->withoutOverlapping();
Schedule::command('biodiversity:cleanup')->dailyAt('03:30')->withoutOverlapping();
