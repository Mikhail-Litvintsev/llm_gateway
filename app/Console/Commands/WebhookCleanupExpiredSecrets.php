<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class WebhookCleanupExpiredSecrets extends Command
{
    protected $signature = 'webhook:cleanup-expired-secrets';

    protected $description = 'Nullify previous signing secrets that have exceeded the grace period';

    public function handle(): int
    {
        $graceSeconds = (int) config('llm.webhook.grace_period_seconds', 86400);

        $cutoff = now()->subSeconds($graceSeconds);

        $affected = DB::table('clients')
            ->whereNotNull('signing_secret_previous_encrypted')
            ->where('signing_secret_rotated_at', '<', $cutoff)
            ->update(['signing_secret_previous_encrypted' => null]);

        $this->info("Expired previous secrets nullified: {$affected} client(s).");

        return self::SUCCESS;
    }
}
