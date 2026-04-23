<?php

namespace App\Console\Commands;

use App\Components\CallbackDelivery\Enums\DeliveryStatus;
use App\Components\RequestPipeline\Enums\RequestStatus;
use App\Models\PendingPrompt;
use App\Models\PendingResponse;
use App\Models\RequestLog;
use Illuminate\Console\Command;

class CleanupExpiredData extends Command
{
    protected $signature = 'llm:cleanup-expired';

    protected $description = 'Remove expired pending prompts and responses';

    public function handle(): int
    {
        // 1. Delete expired pending_prompts
        $deletedPrompts = PendingPrompt::where('expires_at', '<', now())->delete();
        $this->info("Deleted {$deletedPrompts} expired pending prompts.");

        // 2. Delete expired pending_responses
        $deletedResponses = PendingResponse::where('expires_at', '<', now())->delete();
        $this->info("Deleted {$deletedResponses} expired pending responses.");

        // 3. Delete delivered pending_responses older than 1 day
        $deliveredCleaned = PendingResponse::where('delivery_status', DeliveryStatus::Delivered)
            ->where('updated_at', '<', now()->subDay())
            ->delete();
        $this->info("Deleted {$deliveredCleaned} delivered pending responses (>1 day).");

        // 4. Mark accepted requests without pending_prompt or response as timed out
        $timedOut = RequestLog::where('status', RequestStatus::Accepted)
            ->whereDoesntHave('pendingPrompt')
            ->whereDoesntHave('responseLog')
            ->where('created_at', '<', now()->subDays(3))
            ->update([
                'status' => RequestStatus::Timeout->value,
                'error_code' => 'PROVIDER_TIMEOUT',
                'error_message' => 'Request expired: no response received within 3 days.',
            ]);
        $this->info("Marked {$timedOut} stale accepted requests as timed out.");

        return self::SUCCESS;
    }
}
