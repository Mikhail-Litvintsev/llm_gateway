<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;

final class ClientEnableFeature extends Command
{
    protected $signature = 'client:enable-feature {client_id : Client ID} {feature : Feature name}';

    protected $description = 'Enable a feature for a client';

    private const array KNOWN_FEATURES = [
        'thinking',
        'web_search',
        'code_execution',
        'computer_use',
        'bash',
        'text_editor',
        'priority_tier',
        'citations',
        'prompt_caching',
        'structured_outputs',
        'batch',
    ];

    /**
     * Enable a known feature on the client's allowed_features list.
     */
    public function handle(): int
    {
        $feature = $this->argument('feature');

        if (! in_array($feature, self::KNOWN_FEATURES, true)) {
            $this->error("Unknown feature: {$feature}");
            $this->error('Known features: ' . implode(', ', self::KNOWN_FEATURES));
            return self::FAILURE;
        }

        $client = Client::find($this->argument('client_id'));

        if (! $client) {
            $this->error('Client not found.');
            return self::FAILURE;
        }

        $features = $client->allowed_features ?? [];
        $features[$feature] = true;

        $client->update(['allowed_features' => $features]);

        $this->info("Feature '{$feature}' enabled for client id={$client->id} name=\"{$client->name}\"");

        return self::SUCCESS;
    }
}
