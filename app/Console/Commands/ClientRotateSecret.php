<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class ClientRotateSecret extends Command
{
    protected $signature = 'client:rotate-secret {client_id : Client ID}';

    protected $description = 'Rotate signing secret with 24h grace period for previous secret';

    /**
     * Move current signing secret to previous, set new current, and print it once.
     */
    public function handle(): int
    {
        $client = Client::find($this->argument('client_id'));

        if (! $client) {
            $this->error('Client not found.');

            return self::FAILURE;
        }

        $plainSecret = 'whsec_'.bin2hex(random_bytes(32));
        $encryptedSecret = Crypt::encryptString($plainSecret);

        DB::transaction(function () use ($client, $encryptedSecret): void {
            DB::table('clients')
                ->where('id', $client->id)
                ->update([
                    'signing_secret_previous_encrypted' => $client->signing_secret_current_encrypted,
                    'signing_secret_current_encrypted' => $encryptedSecret,
                    'signing_secret_rotated_at' => now(),
                ]);
        });

        $this->info("Signing secret rotated for client id={$client->id} name=\"{$client->name}\"");
        $this->info('================================================================');
        $this->info('NEW SIGNING SECRET (save now, will not be shown again):');
        $this->info("  {$plainSecret}");
        $this->info('================================================================');
        $this->warn('Previous secret valid for 24 hours. After that, `webhook:cleanup-expired-secrets` will purge it.');

        return self::SUCCESS;
    }
}
