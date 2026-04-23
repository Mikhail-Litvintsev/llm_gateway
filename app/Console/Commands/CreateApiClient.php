<?php

namespace App\Console\Commands;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\ApiClient;
use Illuminate\Console\Command;

class CreateApiClient extends Command
{
    protected $signature = 'llm:create-client
        {name : Client name}
        {--rate-limit=60 : Requests per minute}
        {--providers= : Comma-separated allowed providers (e.g. claude,openai)}
        {--no-dev-mode : Create client with dev_mode disabled}';

    protected $description = 'Create a new API client with generated keys';

    public function handle(KeyGenerator $generator, KeyHasher $hasher): int
    {
        $name = $this->argument('name');
        $rateLimit = (int) $this->option('rate-limit');
        $providers = $this->option('providers')
            ? explode(',', $this->option('providers'))
            : null;

        $apiKey = $generator->generate();
        $signingSecret = $generator->generate('lgs_');

        $client = ApiClient::create([
            'name' => $name,
            'api_key_hash' => $hasher->hash($apiKey),
            'api_key_prefix' => $hasher->extractPrefix($apiKey),
            'signing_secret' => $signingSecret,
            'is_active' => true,
            'rate_limit' => $rateLimit,
            'allowed_providers' => $providers,
            'dev_mode' => !$this->option('no-dev-mode'),
        ]);

        $this->info("Client created: {$client->name} (ID: {$client->id})");
        $devModeLabel = $client->dev_mode ? 'ON' : 'OFF';
        $this->info("Dev mode: {$devModeLabel} (use llm:toggle-dev-mode to switch)");
        $this->newLine();
        $this->warn('Save these credentials — they will not be shown again:');
        $this->line("API Key:        {$apiKey}");
        $this->line("Signing Secret: {$signingSecret}");

        return self::SUCCESS;
    }
}
