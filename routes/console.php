<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(new \App\Jobs\Scheduled\RetryFailedWebhooks())->everyMinute()->name('retry-failed-webhooks')->onOneServer();
Schedule::job(new \App\Jobs\Scheduled\ClaudeApiPingScheduled, 'low')->everyMinute()->name('claude-api-ping')->onOneServer();
Schedule::command('claude:poll-batches')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('claude:flush-accumulator')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('requests:cleanup')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('webhook:cleanup-expired-secrets')->hourly()->withoutOverlapping();
Schedule::command('claude:cleanup-files')->weekly()->sundays()->at('03:00')->withoutOverlapping()->onOneServer();
Schedule::command('claude:sync-capabilities')->weekly()->sundays()->at('03:00')->withoutOverlapping()->onOneServer();
