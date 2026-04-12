<?php

declare(strict_types=1);

namespace App\Components\DevMode;

use App\Components\Claude\DTO\MessageRequest;
use App\Components\Claude\DTO\StreamEvent;
use App\Components\Claude\DTO\UsageData;
use App\Components\DevMode\DTO\StubbedResponse;
use App\Models\Client;
use Generator;
use Illuminate\Support\Str;

final class DevModeStubber
{
    private readonly string $stubContent;

    private readonly int $latencyMs;

    private readonly float $cacheHitRate;

    public function __construct()
    {
        $this->stubContent = (string) config('llm.dev_mode.content', 'This is a dev_mode stub response.');
        $this->latencyMs = (int) config('llm.dev_mode.latency_ms', 150);
        $this->cacheHitRate = (float) config('llm.dev_mode.simulate_cache_hit_rate', 0.5);
    }

    public function buildMessageResponse(MessageRequest $request, Client $client): StubbedResponse
    {
        $stubId = 'msg_stub_'.Str::random(24);
        $contentBlocks = $this->buildContentBlocks($request);
        $inputTokens = (int) ceil(strlen(json_encode($request->messages)) / 4);
        $outputTokens = (int) ceil(strlen($this->stubContent) / 4);
        $cacheHit = $this->shouldSimulateCacheHit($request);
        $cacheReadTokens = $cacheHit ? (int) ceil($inputTokens * 0.6) : 0;

        $usage = new UsageData(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheCreation5mTokens: 0,
            cacheCreation1hTokens: 0,
            cacheReadTokens: $cacheReadTokens,
            thinkingTokens: 0,
            serverToolWebSearchCount: 0,
            serverToolWebFetchCount: 0,
            serverToolCodeExecCount: 0,
            serverToolToolSearchCount: 0,
        );

        $body = [
            'id' => $stubId,
            'type' => 'message',
            'role' => 'assistant',
            'model' => config("llm.claude.model_aliases.{$request->modelAlias}", $request->modelAlias),
            'content' => $contentBlocks,
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => $cacheReadTokens,
            ],
        ];

        $headers = [
            'anthropic-request-id' => $stubId,
            'x-gateway-dev-mode' => 'true',
            'anthropic-ratelimit-requests-limit' => '1000',
            'anthropic-ratelimit-requests-remaining' => '999',
            'anthropic-ratelimit-tokens-limit' => '100000',
            'anthropic-ratelimit-tokens-remaining' => '99000',
        ];

        return new StubbedResponse($body, $headers, $usage);
    }

    /** @return Generator<StreamEvent> */
    public function buildStreamEvents(MessageRequest $request, Client $client): Generator
    {
        $stubId = 'msg_stub_'.Str::random(24);
        $model = config("llm.claude.model_aliases.{$request->modelAlias}", $request->modelAlias);
        $words = explode(' ', $this->stubContent);
        $chunks = array_chunk($words, max(1, (int) ceil(count($words) / 5)));
        $sleepUs = count($chunks) > 0 ? (int) (($this->latencyMs * 1000) / count($chunks)) : 0;

        $inputTokens = (int) ceil(strlen(json_encode($request->messages)) / 4);
        $outputTokens = (int) ceil(strlen($this->stubContent) / 4);

        yield new StreamEvent('message_start', [
            'type' => 'message_start',
            'message' => [
                'id' => $stubId,
                'type' => 'message',
                'role' => 'assistant',
                'model' => $model,
                'content' => [],
                'stop_reason' => null,
                'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => 0],
            ],
        ]);

        yield new StreamEvent('content_block_start', [
            'type' => 'content_block_start',
            'index' => 0,
            'content_block' => ['type' => 'text', 'text' => ''],
        ]);

        foreach ($chunks as $chunk) {
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
            yield new StreamEvent('content_block_delta', [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => implode(' ', $chunk).' '],
            ]);
        }

        yield new StreamEvent('content_block_stop', [
            'type' => 'content_block_stop',
            'index' => 0,
        ]);

        yield new StreamEvent('message_delta', [
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'end_turn'],
            'usage' => ['output_tokens' => $outputTokens],
        ]);

        yield new StreamEvent('message_stop', [
            'type' => 'message_stop',
        ]);
    }

    private function buildContentBlocks(MessageRequest $request): array
    {
        $blocks = [];

        if ($request->thinking !== null) {
            $blocks[] = [
                'type' => 'thinking',
                'thinking' => 'Analyzing the request in dev mode...',
                'signature' => 'sig_stub_'.bin2hex(random_bytes(16)),
            ];
        }

        $hasWebSearch = false;
        if ($request->tools !== null) {
            foreach ($request->tools as $tool) {
                if (($tool['name'] ?? '') === 'web_search_20250305') {
                    $hasWebSearch = true;
                    break;
                }
            }
        }

        if ($hasWebSearch) {
            $blocks[] = [
                'type' => 'server_tool_use',
                'id' => 'srvtoolu_stub_'.Str::random(12),
                'name' => 'web_search_20250305',
                'input' => ['query' => 'dev mode stub query'],
            ];
            $blocks[] = [
                'type' => 'web_search_tool_result',
                'tool_use_id' => $blocks[count($blocks) - 1]['id'],
                'content' => [
                    ['type' => 'web_search_result', 'url' => 'https://example.com', 'title' => 'Stub result', 'snippet' => 'Dev mode search result'],
                ],
            ];
        }

        $blocks[] = [
            'type' => 'text',
            'text' => $this->stubContent,
        ];

        if ($request->tools !== null && ! $hasWebSearch) {
            $firstTool = $request->tools[0];
            $blocks[] = [
                'type' => 'tool_use',
                'id' => 'toolu_stub_'.Str::random(12),
                'name' => $firstTool['name'] ?? 'unknown_tool',
                'input' => (object) [],
            ];
        }

        return $blocks;
    }

    private function shouldSimulateCacheHit(MessageRequest $request): bool
    {
        $hash = crc32($request->modelAlias.json_encode($request->messages));

        return (abs($hash) % 100) < ($this->cacheHitRate * 100);
    }
}
