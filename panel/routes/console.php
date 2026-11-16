<?php

use Illuminate\Support\Facades\Schedule;

// Needs the scheduler running: "php artisan schedule:work", or a cron entry for "schedule:run".
Schedule::command('schedules:run')->everyMinute();
Schedule::command('servers:watch')->everyMinute();
// Looks at the nodes every two seconds for that minute, and only while a page is open.
Schedule::command('servers:broadcast')->everyMinute()->runInBackground()->withoutOverlapping(2);
// Keeps the activity log from growing forever; the retention is a panel setting.
Schedule::command('activity:prune')->dailyAt('03:10');
