<?php

namespace App\Console\Commands;

use App\Components\CallbackDelivery\Enums\DeliveryStatus;
use App\Jobs\DeliverCallback;
use App\Models\PendingResponse;
use Illuminate\Console\Command;

class RetryCallbacks extends Command
{
    protected $signature = 'llm:retry-callbacks';

    protected $description = 'Retry pending callback deliveries that are due';

    public function handle(): void
    {
        PendingResponse::where('delivery_status', DeliveryStatus::Pending)
            ->where('next_retry_at', '<=', now())
            ->each(function (PendingResponse $pending) {
                DeliverCallback::dispatch($pending->request_log_id);
            });
    }
}
