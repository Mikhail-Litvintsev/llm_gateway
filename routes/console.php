<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('llm:retry-callbacks')->everyMinute();
Schedule::command('llm:cleanup-expired')->hourly()->withoutOverlapping()->appendOutputTo(storage_path('logs/cleanup.log'));
Schedule::command('llm:mark-timed-out')->everyFiveMinutes()->withoutOverlapping();
