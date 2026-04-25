<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Components\Delivery\Webhook\DTO\SignedRequest;
use App\Components\Delivery\Webhook\DTO\WebhookEnvelope;
use App\Components\Delivery\Webhook\Enums\WebhookDeliveryOutcome;
use App\Components\Delivery\Webhook\Enums\WebhookEvent;
use App\Components\Delivery\Webhook\Webhook;
use App\Components\Logging\Enums\RequestStatus;
use App\Models\ApiRequest;
use App\Models\Client;
use App\Repositories\AsyncPendingRepository;
use App\Repositories\DTO\RequestDetails;
use App\Repositories\RequestRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    public function handle(
        Webhook $webhook,
        RequestRepository $requests,
        AsyncPendingRepository $pending,
    ): void {
        $pendingRow = $pending->find($this->requestId);
        if ($pendingRow === null || in_array($pendingRow->status, ['delivered', 'exhausted'], true)) {
            return;
        }

        $details = $requests->findDetails($this->requestId, true);
        if (! $details->exists()) {
            return;
        }

        $client = Client::find($details->request->client_id);
        if ($client === null) {
            return;
        }

        $envelope = $this->buildEnvelope($details, $client);
        $signed = $webhook->buildSignedRequest($client, $envelope);

        $outcome = $this->attemptDelivery($signed, $pendingRow->callback_url);
        $newAttempts = $pendingRow->callback_attempts + 1;
        $maxAttempts = $this->resolveMaxAttempts($client);

        match ($outcome) {
            WebhookDeliveryOutcome::Success => $pending->markDelivered($this->requestId, $newAttempts),
            WebhookDeliveryOutcome::PermanentFail => $this->markExhaustedPermanentFail(
                $client,
                $newAttempts,
                $requests,
                $pending,
            ),
            WebhookDeliveryOutcome::TransientFail => $newAttempts >= $maxAttempts
                ? $this->markExhausted($client, $newAttempts, $requests, $pending)
                : $pending->scheduleRetry(
                    $this->requestId,
                    $newAttempts,
                    now()->addSeconds($this->computeBackoffDelaySeconds($newAttempts)),
                ),
        };
    }

    public function failed(Throwable $exception): void
    {
        $requests = app(RequestRepository::class);
        $pending = app(AsyncPendingRepository::class);

        $pendingRow = $pending->find($this->requestId);
        if ($pendingRow === null || in_array($pendingRow->status, ['delivered', 'exhausted'], true)) {
            Log::channel('llm')->error('DeliverWebhook::failed — no actionable pending record', [
                'request_id' => $this->requestId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        $requestRow = $requests->find($this->requestId);
        $client = $requestRow !== null ? Client::find($requestRow->client_id) : null;

        $newAttempts = $pendingRow->callback_attempts + 1;
        $maxAttempts = $this->resolveMaxAttempts($client);

        if ($newAttempts >= $maxAttempts) {
            $pending->markExhausted($this->requestId, $newAttempts);
            $requests->setStatus($this->requestId, RequestStatus::FailedCallbackDelivery->value);

            Log::channel('llm')->error('Webhook delivery exhausted', [
                'request_id' => $this->requestId,
                'client_id' => $client?->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'attempts' => $newAttempts,
                'reason' => 'transient_fail',
            ]);

            return;
        }

        $this->logFailureRecovery($exception, $newAttempts, $maxAttempts);
        $pending->scheduleRetry(
            $this->requestId,
            $newAttempts,
            now()->addSeconds($this->computeBackoffDelaySeconds($newAttempts)),
        );
    }

    private function attemptDelivery(SignedRequest $signed, string $callbackUrl): WebhookDeliveryOutcome
    {
        try {
            $response = Http::withHeaders($signed->headers)
                ->withBody($signed->body, 'application/json')
                ->timeout((int) config('llm.webhook.request_timeout_seconds', 30))
                ->connectTimeout((int) config('llm.webhook.connect_timeout_seconds', 5))
                ->post($callbackUrl);

            if ($response->successful()) {
                return WebhookDeliveryOutcome::Success;
            }

            return $this->isPermanentFailStatus($response->status())
                ? WebhookDeliveryOutcome::PermanentFail
                : WebhookDeliveryOutcome::TransientFail;
        } catch (ConnectionException|RequestException) {
            return WebhookDeliveryOutcome::TransientFail;
        }
    }

    private function isPermanentFailStatus(int $status): bool
    {
        /** @var int[] $statuses */
        $statuses = (array) config('llm.webhook.permanent_fail_statuses', [400, 401, 403, 404, 410, 413, 422]);

        return in_array($status, $statuses, true);
    }

    private function markExhausted(
        Client $client,
        int $newAttempts,
        RequestRepository $requests,
        AsyncPendingRepository $pending,
    ): void {
        $pending->markExhausted($this->requestId, $newAttempts);
        $requests->setStatus($this->requestId, RequestStatus::FailedCallbackDelivery->value);

        Log::channel('llm')->error('Webhook delivery exhausted', [
            'request_id' => $this->requestId,
            'client_id' => $client->id,
            'attempts' => $newAttempts,
            'reason' => 'transient_fail',
        ]);
    }

    private function markExhaustedPermanentFail(
        Client $client,
        int $newAttempts,
        RequestRepository $requests,
        AsyncPendingRepository $pending,
    ): void {
        $pending->markExhausted($this->requestId, $newAttempts);
        $requests->setStatus($this->requestId, RequestStatus::FailedCallbackDelivery->value);

        Log::channel('llm')->error('Webhook delivery exhausted', [
            'request_id' => $this->requestId,
            'client_id' => $client->id,
            'attempts' => $newAttempts,
            'reason' => 'permanent_fail',
        ]);
    }

    private function logFailureRecovery(Throwable $exception, int $newAttempts, int $maxAttempts): void
    {
        Log::channel('llm')->error('DeliverWebhook::failed — scheduling recovery', [
            'request_id' => $this->requestId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $newAttempts,
            'max_attempts' => $maxAttempts,
        ]);
    }

    private function resolveMaxAttempts(?Client $client): int
    {
        return (int) ($client?->allowed_features['webhook_max_attempts']
            ?? config('llm.webhook.default_max_attempts', 10));
    }

    private function buildEnvelope(RequestDetails $details, Client $client): WebhookEnvelope
    {
        $request = $details->request;
        assert($request instanceof ApiRequest);
        $usage = $details->usage;
        $raw = $details->raw;

        $isCompleted = in_array($request->status, [
            RequestStatus::Completed->value,
            RequestStatus::CompletedDisconnected->value,
        ], true);

        $event = $isCompleted ? WebhookEvent::MessageCompleted : WebhookEvent::MessageFailed;

        $anthropicResponse = null;
        if ($isCompleted && $raw?->response_payload !== null) {
            $decoded = json_decode($raw->response_payload, true);
            $anthropicResponse = is_array($decoded) ? $decoded : null;
        }

        $error = null;
        if (! $isCompleted) {
            $error = [
                'type' => $request->error_type ?? 'unknown',
                'message' => $request->error_message ?? 'Unknown error',
            ];
        }

        $capUsd = $client->monthly_spend_cap_usd !== null ? (float) $client->monthly_spend_cap_usd : null;
        $currentSpend = (float) $client->current_month_spend_usd;

        return new WebhookEnvelope(
            requestId: $request->request_id,
            event: $event,
            anthropicRequestId: $request->anthropic_request_id,
            modelAlias: $request->model_alias,
            modelSnapshot: $request->model_snapshot,
            anthropicResponse: $anthropicResponse,
            error: $error,
            billing: [
                'cost_usd' => $usage !== null ? (float) $usage->cost_usd : 0.0,
                'cost_breakdown' => $usage !== null ? ($usage->cost_breakdown ?? []) : [],
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
