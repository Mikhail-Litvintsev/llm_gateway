<?php

declare(strict_types=1);

namespace App\Components\Delivery\Stream;

use App\Components\Claude\DTO\UsageData;
use App\Components\Delivery\Stream\DTO\StreamContext;
use App\Components\Delivery\Stream\DTO\StreamOutcome;
use App\Components\Pricing\CostCalculator;
use App\Components\RateLimiting\Claude\ClaudeRateLimitTracker;
use App\Components\RateLimiting\Claude\RateLimitNamespace;
use App\Components\Routing\WorkspaceResolver;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class StreamResponder
{
    public function __construct(
        private WorkspaceResolver $workspaces,
        private StreamEventParser $parserPrototype,
        private CostCalculator $costCalculator,
        private ClaudeRateLimitTracker $rateLimitTracker,
    ) {}

    /**
     * Opens an SSE connection to Anthropic, passes through the byte stream to the client,
     * and handles client disconnect by continuing to drain upstream until message_stop
     * so billing usage is captured correctly.
     *
     * The $context->onComplete callback is invoked with a StreamOutcome once the stream
     * finishes (whether connected or disconnected). The caller is responsible for
     * billing and logging in that callback.
     */
    public function stream(StreamContext $context): StreamedResponse
    {
        $workspace = $this->workspaces->resolveForClient($context->client);
        $parser = clone $this->parserPrototype;
        $parser->reset();

        $response = new StreamedResponse(function () use ($context, $workspace, $parser): void {
            ignore_user_abort(true);
            @ob_end_clean();

            $startMs = (int) (microtime(true) * 1000);
            $disconnected = false;
            $checkEveryN = (int) config('llm.claude.streaming.disconnect_check_interval', 5);
            $counter = 0;

            $headers = [
                'x-api-key' => $workspace->apiKey,
                'anthropic-version' => config('llm.claude.anthropic_version', '2023-06-01'),
                'content-type' => 'application/json',
            ];

            if ($context->payload->betaHeaders !== []) {
                $headers['anthropic-beta'] = implode(',', $context->payload->betaHeaders);
            }

            $guzzleResponse = Http::withHeaders($headers)
                ->withBody($context->payload->jsonBody)
                ->withOptions([
                    'stream' => true,
                    'read_timeout' => (int) config('llm.claude.streaming.read_timeout', 600),
                ])
                ->timeout(0)
                ->post((string) config('llm.claude.endpoints.messages'));

            $httpStatus = $guzzleResponse->status();
            $responseHeaders = $guzzleResponse->headers();

            $this->rateLimitTracker->recordFromHeaders(
                RateLimitNamespace::Messages,
                md5($workspace->apiKey),
                $context->payload->modelSnapshot,
                $responseHeaders,
            );

            if ($httpStatus !== 200) {
                $rawBody = $guzzleResponse->body();
                echo $rawBody;

                ($context->onComplete)(new StreamOutcome(
                    aggregate: $parser->aggregate(),
                    costUsd: 0.0,
                    costBreakdown: [],
                    latencyMs: ((int) (microtime(true) * 1000)) - $startMs,
                    clientDisconnected: false,
                    completed: false,
                    errorType: 'upstream_error',
                    anthropicHeaders: $responseHeaders,
                    httpStatusCode: $httpStatus,
                    firstChunkBody: $rawBody,
                ));

                return;
            }

            $body = $guzzleResponse->toPsrResponse()->getBody();
            $buffer = '';

            while (! $body->eof()) {
                $chunk = $body->read(8192);

                if ($chunk === '') {
                    continue;
                }

                if (! $disconnected) {
                    echo $chunk;
                    @ob_flush();
                    @flush();
                }

                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $rawEvent = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    [$eventName, $dataJson] = $this->splitSseEvent($rawEvent);

                    if ($eventName !== null) {
                        $parser->consume($eventName, $dataJson);
                    }
                }

                $counter++;

                if (! $disconnected && $counter % $checkEveryN === 0) {
                    if (connection_aborted() === 1) {
                        $disconnected = true;
                    }
                }

                if ($parser->aggregate()->completed || $parser->aggregate()->errored) {
                    break;
                }
            }

            $latencyMs = ((int) (microtime(true) * 1000)) - $startMs;
            $aggregate = $parser->aggregate();

            $usageData = new UsageData(
                inputTokens: $aggregate->inputTokens ?? 0,
                outputTokens: $aggregate->outputTokens ?? 0,
                cacheCreation5mTokens: $aggregate->cacheCreationInputTokens ?? 0,
                cacheCreation1hTokens: 0,
                cacheReadTokens: $aggregate->cacheReadInputTokens ?? 0,
                thinkingTokens: $aggregate->thinkingTokens ?? 0,
                serverToolWebSearchCount: 0,
                serverToolWebFetchCount: 0,
                serverToolCodeExecCount: 0,
                serverToolToolSearchCount: 0,
            );

            $costBreakdown = $this->costCalculator->calculate(
                $usageData,
                $context->payload->modelAlias,
                false,
                false,
            );

            $breakdownArray = [
                'input' => $costBreakdown->inputCost->toFloat(),
                'output' => $costBreakdown->outputCost->toFloat(),
                'cache_write_5m' => $costBreakdown->cacheWrite5mCost->toFloat(),
                'cache_write_1h' => $costBreakdown->cacheWrite1hCost->toFloat(),
                'cache_read' => $costBreakdown->cacheReadCost->toFloat(),
                'server_tool_web_search' => $costBreakdown->serverToolWebSearchCost->toFloat(),
                'server_tool_code_exec' => $costBreakdown->serverToolCodeExecCost->toFloat(),
                'total' => $costBreakdown->totalCost->toFloat(),
            ];

            ($context->onComplete)(new StreamOutcome(
                aggregate: $aggregate,
                costUsd: $costBreakdown->totalCost->toFloat(),
                costBreakdown: $breakdownArray,
                latencyMs: $latencyMs,
                clientDisconnected: $disconnected,
                completed: $aggregate->completed,
                errorType: $aggregate->errored ? 'stream_error' : null,
                anthropicHeaders: $responseHeaders,
                httpStatusCode: 200,
            ));
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('X-Gateway-Request-Id', $context->gatewayRequestId);
        $response->headers->set('X-Gateway-Model-Alias', $context->payload->modelAlias);
        $response->headers->set('X-Gateway-Model-Snapshot', $context->payload->modelSnapshot);

        return $response;
    }

    /**
     * @return array{0: ?string, 1: string} [eventName, dataJson]
     */
    private function splitSseEvent(string $rawEvent): array
    {
        $eventName = null;
        $dataLines = [];

        foreach (explode("\n", $rawEvent) as $line) {
            if (str_starts_with($line, ':')) {
                continue;
            }

            if (str_starts_with($line, 'event: ')) {
                $eventName = substr($line, 7);
                continue;
            }

            if (str_starts_with($line, 'data: ')) {
                $dataLines[] = substr($line, 6);
            }
        }

        return [$eventName, implode("\n", $dataLines)];
    }
}
