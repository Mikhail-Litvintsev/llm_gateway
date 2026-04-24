<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Components\Delivery\Webhook\DTO\WebhookEnvelope;
use App\Components\Delivery\Webhook\Enums\WebhookEvent;
use App\Components\Delivery\Webhook\Webhook;
use App\Components\Logging\Enums\RequestStatus;
use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $requestId) {}

    /**
     * Delivers signed webhook to client callback URL.
     * On success: status → delivered.
     * On failure with attempts remaining: status → processing, next_attempt_at set with exponential backoff.
     * On exhaustion (max attempts reached): status → exhausted, requests.status → failed_callback_delivery.
     */
    public function handle(Webhook $webhook): void
    {
        $pending = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        if (! $pending || in_array($pending->status, ['delivered', 'exhausted'], true)) {
            return;
        }

        $requestRow = DB::table('requests')->where('request_id', $this->requestId)->first();
        if (! $requestRow) {
            return;
        }

        $client = Client::find($requestRow->client_id);
        if (! $client) {
            return;
        }

        $envelope = $this->buildEnvelope($requestRow, $client);
        $signed = $webhook->buildSignedRequest($client, $envelope);

        try {
            $response = Http::withHeaders($signed->headers)
                ->withBody($signed->body, 'application/json')
                ->timeout((int) config('llm.webhook.request_timeout_seconds', 30))
                ->connectTimeout((int) config('llm.webhook.connect_timeout_seconds', 5))
                ->post($pending->callback_url);

            $success = $response->successful();
        } catch (ConnectionException|RequestException) {
            $success = false;
        }

        $newAttempts = $pending->callback_attempts + 1;
        $maxAttempts = $this->resolveMaxAttempts($client);

        if ($success) {
            DB::table('async_pending')->where('request_id', $this->requestId)->update([
                'status' => 'delivered',
                'callback_attempts' => $newAttempts,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);

            return;
        }

        if ($newAttempts >= $maxAttempts) {
            DB::table('async_pending')->where('request_id', $this->requestId)->update([
                'status' => 'exhausted',
                'callback_attempts' => $newAttempts,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
            DB::table('requests')->where('request_id', $this->requestId)->update([
                'status' => RequestStatus::FailedCallbackDelivery->value,
            ]);
            Log::channel('llm')->error('Webhook delivery exhausted', [
                'request_id' => $this->requestId,
                'client_id' => $client->id,
                'attempts' => $newAttempts,
            ]);

            return;
        }

        $delay = $this->computeBackoffDelaySeconds($newAttempts);
        DB::table('async_pending')->where('request_id', $this->requestId)->update([
            'status' => 'processing',
            'callback_attempts' => $newAttempts,
            'next_attempt_at' => now()->addSeconds($delay),
            'updated_at' => now(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $pending = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        if (! $pending || in_array($pending->status, ['delivered', 'exhausted'], true)) {
            Log::channel('llm')->error('DeliverWebhook::failed — no actionable pending record', [
                'request_id' => $this->requestId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        $requestRow = DB::table('requests')->where('request_id', $this->requestId)->first();
        $client = $requestRow ? Client::find($requestRow->client_id) : null;

        $newAttempts = $pending->callback_attempts + 1;
        $maxAttempts = $this->resolveMaxAttempts($client);

        Log::channel('llm')->error('DeliverWebhook::failed — scheduling recovery', [
            'request_id' => $this->requestId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $newAttempts,
            'max_attempts' => $maxAttempts,
        ]);

        if ($newAttempts >= $maxAttempts) {
            DB::table('async_pending')->where('request_id', $this->requestId)->update([
                'status' => 'exhausted',
                'callback_attempts' => $newAttempts,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
            DB::table('requests')->where('request_id', $this->requestId)->update([
                'status' => RequestStatus::FailedCallbackDelivery->value,
            ]);

            return;
        }

        $delay = $this->computeBackoffDelaySeconds($newAttempts);
        DB::table('async_pending')->where('request_id', $this->requestId)->update([
            'status' => 'processing',
            'callback_attempts' => $newAttempts,
            'next_attempt_at' => now()->addSeconds($delay),
            'updated_at' => now(),
        ]);
    }

    private function resolveMaxAttempts(?Client $client): int
    {
        return (int) ($client?->allowed_features['webhook_max_attempts']
            ?? config('llm.webhook.default_max_attempts', 10));
    }

    private function buildEnvelope(object $requestRow, Client $client): WebhookEnvelope
    {
        $usage = DB::table('request_usage')->where('request_id', $requestRow->request_id)->first();
        $raw = DB::table('request_raw')->where('request_id', $requestRow->request_id)->first();

        $isCompleted = in_array($requestRow->status, [
            RequestStatus::Completed->value,
            RequestStatus::CompletedDisconnected->value,
        ], true);

        $event = $isCompleted ? WebhookEvent::MessageCompleted : WebhookEvent::MessageFailed;

        $anthropicResponse = null;
        if ($isCompleted && $raw?->response_payload) {
            $anthropicResponse = json_decode($raw->response_payload, true);
        }

        $error = null;
        if (! $isCompleted) {
            $error = [
                'type' => $requestRow->error_type ?? 'unknown',
                'message' => $requestRow->error_message ?? 'Unknown error',
            ];
        }

        $capUsd = $client->monthly_spend_cap_usd !== null ? (float) $client->monthly_spend_cap_usd : null;
        $currentSpend = (float) $client->current_month_spend_usd;

        return new WebhookEnvelope(
            requestId: $requestRow->request_id,
            event: $event,
            anthropicRequestId: $requestRow->anthropic_request_id,
            modelAlias: $requestRow->model_alias,
            modelSnapshot: $requestRow->model_snapshot,
            anthropicResponse: $anthropicResponse,
            error: $error,
            billing: [
                'cost_usd' => $usage ? (float) $usage->cost_usd : 0.0,
                'cost_breakdown' => $usage?->cost_breakdown ? json_decode($usage->cost_breakdown, true) : [],
                'monthly_spend_after_usd' => $currentSpend,
                'monthly_spend_remaining_usd' => $capUsd !== null ? max(0.0, $capUsd - $currentSpend) : null,
            ],
        );
    }

    private function computeBackoffDelaySeconds(int $attempts): int
    {
        $initial = (int) config('llm.webhook.initial_delay_seconds', 10);
        $max = (int) config('llm.webhook.max_delay_seconds', 3600);

        return min($initial * (2 ** ($attempts - 1)), $max);
    }
}
