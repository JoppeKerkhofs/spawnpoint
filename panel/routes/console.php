<?php

use Illuminate\Support\Facades\Schedule;

// Needs the scheduler running: "php artisan schedule:work", or a cron entry for "schedule:run".
Schedule::command('schedules:run')->everyMinute();
Schedule::command('servers:watch')->everyMinute();
// Keeps the activity log from growing forever; the retention is a panel setting.
Schedule::command('activity:prune')->dailyAt('03:10');
