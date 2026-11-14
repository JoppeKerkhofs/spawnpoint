<?php

use Illuminate\Support\Facades\Schedule;

// Needs the scheduler running: "php artisan schedule:work", or a cron entry for "schedule:run".
Schedule::command('schedules:run')->everyMinute();
Schedule::command('servers:watch')->everyMinute();
