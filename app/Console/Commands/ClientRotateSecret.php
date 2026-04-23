<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Components\Auth\Auth;
use App\Models\Client;
use Illuminate\Console\Command;

final class ClientRotateSecret extends Command
{
    protected $signature = 'client:rotate-secret {client_id : Client ID}';

    protected $description = 'Rotate signing secret with 24h grace period for previous secret';

    public function handle(Auth $auth): int
    {
        $client = Client::find($this->argument('client_id'));

        if (! $client) {
            $this->error('Client not found.');

            return self::FAILURE;
        }

        $plainSecret = $auth->rotateSigningSecret($client);

        $this->info("Signing secret rotated for client id={$client->id} name=\"{$client->name}\"");
        $this->info('================================================================');
        $this->info('NEW SIGNING SECRET (save now, will not be shown again):');
        $this->info("  {$plainSecret}");
        $this->info('================================================================');
        $this->warn('Previous secret valid for 24 hours. After that, `webhook:cleanup-expired-secrets` will purge it.');

        return self::SUCCESS;
    }
}
