<?php

use Illuminate\Support\Facades\Schedule;

// Needs the scheduler running: "php artisan schedule:work", or a cron entry for "schedule:run".
Schedule::command('backups:run')->everyMinute();
