<?php

use Illuminate\Support\Facades\Schedule;

// Needs the scheduler running: "php artisan schedule:work", or a cron entry for "schedule:run".
Schedule::command('schedules:run')->everyMinute();
Schedule::command('servers:watch')->everyMinute();
// Looks at the nodes every two seconds for that minute, and only while a page is open.
Schedule::command('servers:broadcast')->everyMinute()->runInBackground()->withoutOverlapping(2);
// Asks Modrinth, CurseForge and FTB about the packs of the servers that follow their updates.
Schedule::command('modpacks:check')->hourly();
// Keeps the activity log from growing forever; the retention is a panel setting.
Schedule::command('activity:prune')->dailyAt('03:10');
