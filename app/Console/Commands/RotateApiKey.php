<?php

namespace App\Console\Commands;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\ApiClient;
use Illuminate\Console\Command;

class RotateApiKey extends Command
{
    protected $signature = 'llm:rotate-key
        {client_name : Client name}
        {--ttl=24 : Hours the old key remains valid}';

    protected $description = 'Rotate API key for a client (old key stays valid during transition period)';

    public function handle(KeyGenerator $generator, KeyHasher $hasher): int
    {
        $client = ApiClient::where('name', $this->argument('client_name'))
            ->where('is_active', true)
            ->first();

        if (!$client) {
            $this->error("Active client '{$this->argument('client_name')}' not found.");
            return self::FAILURE;
        }

        $newApiKey = $generator->generate();
        $ttlHours = (int) $this->option('ttl');

        $client->update([
            'previous_key_hash' => $client->api_key_hash,
            'previous_key_expires_at' => now()->addHours($ttlHours),
            'api_key_hash' => $hasher->hash($newApiKey),
            'api_key_prefix' => $hasher->extractPrefix($newApiKey),
        ]);

        $this->info("API key rotated for client '{$client->name}'.");
        $this->info("Old key valid for {$ttlHours} hours.");
        $this->newLine();
        $this->warn('Save this credential — it will not be shown again:');
        $this->line("New API Key: {$newApiKey}");

        return self::SUCCESS;
    }
}
