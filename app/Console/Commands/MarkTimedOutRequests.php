<?php

namespace App\Console\Commands;

use App\Components\RequestPipeline\Enums\RequestStatus;
use App\Models\RequestLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MarkTimedOutRequests extends Command
{
    protected $signature = 'llm:mark-timed-out';

    protected $description = 'Mark requests stuck in processing for over 30 minutes as timed out';

    public function handle(): int
    {
        $updated = RequestLog::where('status', RequestStatus::Processing)
            ->where('updated_at', '<', now()->subMinutes(30))
            ->update([
                'status' => RequestStatus::Timeout->value,
                'error_code' => 'PROVIDER_TIMEOUT',
                'error_message' => 'Request timed out: stuck in processing for over 30 minutes.',
            ]);

        if ($updated > 0) {
            Log::channel('llm')->warning("Timed out {$updated} stale requests.");
        }

        $this->info("Marked {$updated} requests as timed out.");

        return self::SUCCESS;
    }
}
