<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Components\Auth\Auth;
use App\Models\Client;
use Illuminate\Console\Command;

final class ClientRotateKey extends Command
{
    protected $signature = 'client:rotate-key {client_id : Client ID}';

    protected $description = 'Rotate API key for a client (atomic, no grace period)';

    public function handle(Auth $auth): int
    {
        $client = Client::find($this->argument('client_id'));

        if (! $client) {
            $this->error('Client not found.');

            return self::FAILURE;
        }

        $plainKey = $auth->rotateApiKey($client);

        $this->info("API key rotated for client id={$client->id} name=\"{$client->name}\"");
        $this->info('================================================================');
        $this->info('NEW API KEY (save now, will not be shown again):');
        $this->info("  {$plainKey}");
        $this->info('================================================================');
        $this->warn('Clients should rotate from a controlled window with retry logic.');

        return self::SUCCESS;
    }
}
