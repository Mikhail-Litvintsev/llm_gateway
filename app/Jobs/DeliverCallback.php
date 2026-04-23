<?php

namespace App\Jobs;

use App\Components\CallbackDelivery\CallbackDelivery;
use App\Components\CallbackDelivery\Enums\DeliveryStatus;
use App\Models\PendingResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverCallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(
        public readonly int $requestLogId,
    ) {
        $this->onQueue('high');
    }

    public function handle(CallbackDelivery $delivery): void
    {
        $pending = PendingResponse::where('request_log_id', $this->requestLogId)
            ->where('delivery_status', '!=', DeliveryStatus::Delivered)
            ->first();

        if (!$pending) {
            return;
        }

        $result = $delivery->deliver($pending);

        // If retry needed — schedule next job
        if (!$result->success && $pending->delivery_status === DeliveryStatus::Pending) {
            $delay = $pending->next_retry_at
                ? now()->diffInSeconds($pending->next_retry_at)
                : 1;

            self::dispatch($this->requestLogId)
                ->delay(now()->addSeconds($delay));
        }
    }
}
