<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('camera:sync-personnel')->everyFiveMinutes();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::job(new \App\Jobs\DetectOverstayVisitorsJob())->everyFifteenMinutes();
Schedule::job(new \App\Jobs\ExpireNoShowVisitsJob())->dailyAt('00:00');

