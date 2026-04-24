<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Components\Authorization\Authorization;
use App\Components\Billing\CostEstimator;
use App\Components\Claude\Claude;
use App\Components\Claude\Payload\PayloadBuilder;
use App\Components\Delivery\Sync\DTO\AnthropicResponseEnvelope;
use App\Components\Messaging\Actions\SendAsyncMessageAction;
use App\Components\Messaging\Actions\SendMessageAction;
use App\Components\Messaging\Actions\StreamMessageAction;
use App\Components\Messaging\DTO\MessageRequestInput;
use App\Components\RateLimiting\Claude\Exceptions\RateLimitExceededException;
use App\Components\Routing\ModelResolver;
use App\Components\Validation\MessageRequestValidator;
use App\Components\Validation\ValidationContext;
use App\Http\Controllers\Controller;
use App\Http\Responders\ErrorResponder;
use App\Models\Client;
use App\Repositories\DTO\RequestDetails;
use App\Repositories\RequestRepository;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MessagesController extends Controller
{
    public function __construct(
        private readonly SendMessageAction $sendAction,
        private readonly SendAsyncMessageAction $asyncAction,
        private readonly StreamMessageAction $streamAction,
        private readonly Claude $claude,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly ModelResolver $modelResolver,
        private readonly Authorization $authorization,
        private readonly CostEstimator $costEstimator,
        private readonly RequestRepository $requests,
        private readonly MessageRequestValidator $validator,
        private readonly ErrorResponder $errorResponder,
    ) {}

    public function send(Request $request): Response|StreamedResponse
    {
        $client = $request->attributes->get('auth.client');
        assert($client instanceof Client);

        $input = new MessageRequestInput(
            client: $client,
            payload: $request->json()->all(),
            gatewayRequestId: 'req_'.Str::random(24),
            startedAt: new DateTimeImmutable,
        );

        if (($input->payload['stream'] ?? false) === true) {
            return $this->streamAction->execute($input);
        }

        return $this->sendAction->execute($input);
    }

    public function async(Request $request): Response
    {
        $client = $request->attributes->get('auth.client');
        assert($client instanceof Client);

        $input = new MessageRequestInput(
            client: $client,
            payload: $request->json()->all(),
            gatewayRequestId: 'req_'.Str::random(24),
            startedAt: new DateTimeImmutable,
            additionalFeatures: ['webhook'],
        );

        return $this->asyncAction->execute($input);
    }

    public function countTokens(Request $request): Response
    {
        $client = $request->attributes->get('auth.client');
        assert($client instanceof Client);

        $gatewayRequestId = 'req_'.Str::random(24);
        $payload = $request->json()->all();

        $validation = $this->validator->validate($payload, ValidationContext::CountTokens, $client);
        if (! $validation->isValid()) {
            return $this->errorResponder->invalidRequest(
                $validation->errors[0]->message ?? 'Validation failed',
                $gatewayRequestId,
            );
        }

        $modelAlias = $payload['model'] ?? $client->default_model_alias ?? config('llm.claude.default_model_alias');
        $this->modelResolver->resolve($modelAlias);

        $authResult = $this->authorization->authorize($client, $modelAlias, []);
        if (! $authResult->allowed) {
            return $this->errorResponder->authorizationError(
                $authResult->reason?->errorType() ?? 'permission_error',
                $authResult->message ?? 'Authorization denied',
                $authResult->reason?->httpStatusCode() ?? 403,
                $gatewayRequestId,
            );
        }

        $builtPayload = $this->payloadBuilder->build($payload, $client);

        try {
            $envelope = $this->claude->countTokens($builtPayload, $client);
        } catch (RateLimitExceededException $e) {
            return $this->errorResponder->rateLimit(
                'Rate limit pre-emptively exceeded on axis: '.$e->axis,
                $e->retryAfterSeconds,
                $gatewayRequestId,
            );
        }

        return $this->buildCountTokensResponse($envelope, $payload, $modelAlias, $gatewayRequestId);
    }

    public function show(Request $request, string $requestId): Response
    {
        $client = $request->attributes->get('auth.client');
        assert($client instanceof Client);

        $details = $this->requests->findDetails($requestId, $this->shouldIncludeResponse($request));

        if (! $details->exists() || $details->request->client_id !== $client->id) {
            return $this->errorResponder->notFound('request not found', $requestId);
        }

        return $this->buildShowResponse($details);
    }

    private function shouldIncludeResponse(Request $request): bool
    {
        return $request->query('include_response', 'true') !== 'false';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildCountTokensResponse(
        AnthropicResponseEnvelope $envelope,
        array $payload,
        string $modelAlias,
        string $gatewayRequestId,
    ): Response {
        $decoded = json_decode($envelope->rawBody, true);
        $inputTokens = is_array($decoded) ? (int) ($decoded['input_tokens'] ?? 0) : 0;
        $maxTokens = (int) ($payload['max_tokens'] ?? 1024);
        $outputFactor = (float) config('llm.claude.count_tokens.output_tokens_factor', 0.5);
        $assumedOutput = (int) ceil($maxTokens * $outputFactor);
        $estimatedCost = $this->costEstimator->estimateFromTokens($inputTokens, $assumedOutput, $modelAlias);

        $response = new Response($envelope->rawBody, $envelope->httpStatusCode);
        foreach ($envelope->anthropicHeaders as $name => $value) {
            $response->headers->set($name, $value);
        }
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('X-Gateway-Request-Id', $gatewayRequestId);
        $response->headers->set('X-Gateway-Estimated-Cost-USD', number_format($estimatedCost, 6, '.', ''));

        return $response;
    }

    private function buildShowResponse(RequestDetails $details): Response
    {
        $request = $details->request;
        assert($request !== null);

        $usage = $details->usage;
        $raw = $details->raw;

        $anthropicResponse = null;
        if ($raw?->response_payload !== null) {
            $decoded = json_decode($raw->response_payload, true);
            $anthropicResponse = is_array($decoded) ? $decoded : null;
        }

        $billing = $usage !== null ? [
            'cost_usd' => (float) $usage->cost_usd,
            'cost_breakdown' => $usage->cost_breakdown,
            'monthly_spend_after_usd' => null,
        ] : null;

        $error = $request->error_type !== null ? [
            'type' => $request->error_type,
            'message' => $request->error_message,
        ] : null;

        $latencyMs = ($request->started_at !== null && $request->completed_at !== null)
            ? (int) $request->started_at->diffInMilliseconds($request->completed_at)
            : null;

        $body = json_encode([
            'request_id' => $request->request_id,
            'status' => $request->status,
            'model_alias' => $request->model_alias,
            'model_snapshot' => $request->model_snapshot,
            'endpoint' => $request->endpoint,
            'mode' => $request->mode,
            'created_at' => $request->created_at->format('Y-m-d H:i:s'),
            'completed_at' => $request->completed_at?->format('Y-m-d H:i:s'),
            'latency_ms' => $latencyMs,
            'anthropic_request_id' => $request->anthropic_request_id,
            'anthropic_response' => $anthropicResponse,
            'billing' => $billing,
            'error' => $error,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new Response($body, 200, [
            'Content-Type' => 'application/json',
            'X-Gateway-Request-Id' => $request->request_id,
        ]);
    }
}
