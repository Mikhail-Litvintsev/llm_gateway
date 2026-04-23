<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\Client;
use Illuminate\Console\Command;

final class ClientRotateKey extends Command
{
    protected $signature = 'client:rotate-key {client_id : Client ID}';

    protected $description = 'Rotate API key for a client (atomic, no grace period)';

    /**
     * Generate a new API key, update the client record, and print the new key once.
     */
    public function handle(KeyGenerator $keyGenerator, KeyHasher $keyHasher): int
    {
        $client = Client::find($this->argument('client_id'));

        if (! $client) {
            $this->error('Client not found.');

            return self::FAILURE;
        }

        $plainKey = $keyGenerator->generateRawKey();
        $hashedKey = $keyHasher->hash($plainKey);
        $prefix = $keyGenerator->derivePrefix($plainKey);

        $client->update([
            'api_key_hash' => $hashedKey,
            'api_key_prefix' => $prefix,
        ]);

        $this->info("API key rotated for client id={$client->id} name=\"{$client->name}\"");
        $this->info('================================================================');
        $this->info('NEW API KEY (save now, will not be shown again):');
        $this->info("  {$plainKey}");
        $this->info('================================================================');
        $this->warn('Clients should rotate from a controlled window with retry logic.');

        return self::SUCCESS;
    }
}
