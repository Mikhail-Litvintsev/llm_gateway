<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use App\Models\CallbackUrl;
use Illuminate\Console\Command;

class AddCallbackUrl extends Command
{
    protected $signature = 'llm:add-callback-url
        {client_name : Client name}
        {url : Callback URL (must be HTTPS)}';

    protected $description = 'Add an allowed callback URL for a client';

    public function handle(): int
    {
        $client = ApiClient::where('name', $this->argument('client_name'))->first();

        if (!$client) {
            $this->error("Client '{$this->argument('client_name')}' not found.");
            return self::FAILURE;
        }

        $url = $this->argument('url');

        if (!str_starts_with($url, 'https://') && !app()->environment('local')) {
            $this->error('Callback URL must use HTTPS.');
            return self::FAILURE;
        }

        CallbackUrl::create([
            'api_client_id' => $client->id,
            'url' => $url,
            'is_active' => true,
        ]);

        $this->info("Callback URL added for client '{$client->name}': {$url}");

        return self::SUCCESS;
    }
}
