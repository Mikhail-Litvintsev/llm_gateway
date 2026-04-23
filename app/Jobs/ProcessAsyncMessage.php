<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Components\Authorization\Authorization;
use App\Components\Billing\Billing;
use App\Components\Caching\Caching;
use App\Components\Claude\Claude;
use App\Components\Claude\DTO\SendMessageInput;
use App\Components\Claude\Payload\PayloadBuilder;
use App\Components\Logging\Enums\RequestStatus;
use App\Components\Logging\Logging;
use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class ProcessAsyncMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $requestId) {}

    public function handle(
        Claude $claude,
        PayloadBuilder $payloadBuilder,
        Authorization $authorization,
        Billing $billing,
        Logging $logging,
        Caching $caching,
    ): void {
        $pending = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        if (! $pending) {
            return;
        }

        $requestRow = DB::table('requests')->where('request_id', $this->requestId)->first();
        if (! $requestRow) {
            return;
        }

        if ($requestRow->status === RequestStatus::Completed->value) {
            return;
        }

        $client = Client::find($requestRow->client_id);
        if (! $client) {
            return;
        }

        $payload = json_decode($pending->payload_for_anthropic, true, 512, JSON_THROW_ON_ERROR);
        $modelAlias = $payload['model'] ?? $client->default_model_alias ?? config('llm.claude.default_model_alias');

        $this->markInProgress();

        $injection = $caching->autoInject($payload, $modelAlias, $client);
        $payload = $injection->payload;
        $builtPayload = $payloadBuilder->build($payload, $client);
        $features = $this->extractFeatures($payload);
        $features[] = 'webhook';

        $output = $claude->sendMessage(new SendMessageInput(
            payload: $builtPayload,
            client: $client,
            gatewayRequestId: $this->requestId,
            featuresUsed: $features,
        ));

        if ($output->isSuccess) {
            $billing->recordSpend($client, $output->costUsd);
        }

        $logging->updateAsyncRecord(
            $this->requestId,
            $output,
            $builtPayload->decodedPayload,
            $features,
        );

        DB::table('async_pending')->where('request_id', $this->requestId)->update([
            'status' => 'processing',
            'updated_at' => now(),
        ]);

        DeliverWebhook::dispatch($this->requestId)->onQueue('default');
    }

    private function markInProgress(): void
    {
        DB::table('async_pending')->where('request_id', $this->requestId)->update([
            'status' => 'processing',
            'updated_at' => now(),
        ]);
        DB::table('requests')->where('request_id', $this->requestId)->update([
            'status' => RequestStatus::InProgress->value,
            'started_at' => now(),
        ]);
    }

    /**
     * @return string[]
     */
    private function extractFeatures(array $payload): array
    {
        $features = [];

        if (isset($payload['thinking'])) {
            $features[] = 'thinking';
        }

        if (isset($payload['tools'])) {
            foreach ($payload['tools'] as $tool) {
                $name = $tool['name'] ?? '';
                if (str_starts_with($name, 'web_search')) {
                    $features[] = 'web_search';
                }
                if ($name === 'code_execution') {
                    $features[] = 'code_execution';
                }
                if (str_starts_with($name, 'computer_')) {
                    $features[] = 'computer_use';
                }
                if ($name === 'bash') {
                    $features[] = 'bash';
                }
                if ($name === 'text_editor') {
                    $features[] = 'text_editor';
                }
            }
        }

        if (($payload['service_tier'] ?? null) === 'auto') {
            $features[] = 'priority_tier';
        }

        if (! empty($payload['citations']['enabled'])) {
            $features[] = 'citations';
        }

        if (isset($payload['output_config'])) {
            $features[] = 'structured_outputs';
        }

        return array_unique($features);
    }
}
