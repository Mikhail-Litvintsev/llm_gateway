<?php

declare(strict_types=1);

namespace App\Jobs\Scheduled;

use App\Jobs\DeliverWebhook;
use Illuminate\Support\Facades\DB;

final class RetryFailedWebhooks
{
    public function __invoke(): void
    {
        $maxAttempts = (int) config('llm.webhook.default_max_attempts', 10);

        $requestIds = DB::table('async_pending')
            ->where('status', 'processing')
            ->where('callback_attempts', '>', 0)
            ->where('callback_attempts', '<', $maxAttempts)
            ->where('next_attempt_at', '<=', now())
            ->limit(500)
            ->pluck('request_id');

        foreach ($requestIds as $requestId) {
            DeliverWebhook::dispatch($requestId)->onQueue('default');
        }
    }
}
