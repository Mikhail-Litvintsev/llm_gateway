<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;

final class ClientCreate extends Command
{
    protected $signature = 'client:create
        {name : Client name}
        {--model-alias= : Default model alias}
        {--rate-limit= : Rate limit requests per minute}
        {--monthly-cap= : Monthly spend cap in USD}
        {--features=* : Allowed features (comma-separated)}';

    protected $description = 'Create a new API client with key and signing secret';

    /**
     * Create a new client with API key and signing secret, printing credentials once.
     */
    public function handle(KeyGenerator $keyGenerator, KeyHasher $keyHasher): int
    {
        $name = $this->argument('name');

        $workspace = ClaudeWorkspace::where('name', 'default')->first();

        $plainKey = $keyGenerator->generateRawKey();
        $hashedKey = $keyHasher->hash($plainKey);
        $prefix = $keyGenerator->derivePrefix($plainKey);

        $plainSecret = 'whsec_'.bin2hex(random_bytes(32));
        $encryptedSecret = Crypt::encryptString($plainSecret);

        $features = $this->parseFeatures($this->option('features'));

        $client = Client::create([
            'name' => $name,
            'workspace_id' => $workspace?->id,
            'api_key_hash' => $hashedKey,
            'api_key_prefix' => $prefix,
            'signing_secret_current_encrypted' => $encryptedSecret,
            'default_model_alias' => $this->option('model-alias'),
            'monthly_spend_cap_usd' => $this->option('monthly-cap'),
            'rate_limit_rpm' => $this->option('rate-limit') ?? 60,
            'allowed_features' => $features ?: null,
        ]);

        $this->info("Client created: id={$client->id} name=\"{$name}\"");
        $this->info('================================================================');
        $this->info('API KEY (save now, will not be shown again):');
        $this->info("  {$plainKey}");
        $this->info('SIGNING SECRET (for webhook verification):');
        $this->info("  {$plainSecret}");
        $this->info('================================================================');

        return self::SUCCESS;
    }

    private function parseFeatures(array $rawFeatures): array
    {
        $features = [];

        foreach ($rawFeatures as $item) {
            foreach (explode(',', $item) as $feature) {
                $feature = trim($feature);
                if ($feature !== '') {
                    $features[$feature] = true;
                }
            }
        }

        return $features;
    }
}
