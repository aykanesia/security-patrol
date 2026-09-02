<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes — jadwal command otomatis
|--------------------------------------------------------------------------
| Aktifkan di server dengan cron:
|   * * * * * cd /path/backend && php artisan schedule:run >> /dev/null 2>&1
|
*/

Schedule::command('patrol:notify-missed')->everyFiveMinutes()->withoutOverlapping();
