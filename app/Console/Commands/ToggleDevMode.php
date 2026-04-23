<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;

class ToggleDevMode extends Command
{
    protected $signature = 'llm:toggle-dev-mode
        {client : Client ID or name}
        {--enable : Enable dev_mode}
        {--disable : Disable dev_mode}';

    protected $description = 'Toggle dev_mode for an API client';

    public function handle(): int
    {
        if ($this->option('enable') && $this->option('disable')) {
            $this->error('Cannot use both --enable and --disable flags.');
            return self::FAILURE;
        }

        $clientArg = $this->argument('client');

        $client = ApiClient::find($clientArg);
        if (!$client) {
            $client = ApiClient::where('name', $clientArg)->first();
        }

        if (!$client) {
            $this->error("Client \"{$clientArg}\" not found.");
            return self::FAILURE;
        }

        $oldValue = $client->dev_mode;

        if ($this->option('enable')) {
            $newValue = true;
        } elseif ($this->option('disable')) {
            $newValue = false;
        } else {
            $newValue = !$oldValue;
        }

        $client->update(['dev_mode' => $newValue]);

        $oldLabel = $oldValue ? 'ON' : 'OFF';
        $newLabel = $newValue ? 'ON' : 'OFF';

        if ($this->option('enable') || $this->option('disable')) {
            $this->info("Client \"{$client->name}\" (ID: {$client->id}): dev_mode changed from {$oldLabel} → {$newLabel}");
        } else {
            $this->info("Client \"{$client->name}\" (ID: {$client->id}): dev_mode is now {$newLabel}");
        }

        return self::SUCCESS;
    }
}
