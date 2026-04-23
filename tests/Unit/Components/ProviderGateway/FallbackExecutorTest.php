<?php

namespace Tests\Unit\Components\ProviderGateway;

use App\Components\PromptAssembler\DTO\AssembledPayload;
use App\Components\PromptAssembler\PromptAssembler;
use App\Components\ProviderGateway\DTO\RawProviderResponse;
use App\Components\ProviderGateway\DTO\ResolvedProvider;
use App\Components\ProviderGateway\FallbackExecutor;
use App\Components\ProviderGateway\ProviderResolver;
use App\Components\RequestPipeline\DTO\CallbackConfig;
use App\Components\RequestPipeline\DTO\MetaData;
use App\Components\RequestPipeline\DTO\ParsedRequest;
use App\Components\RequestPipeline\DTO\PromptBlock;
use App\Components\RequestPipeline\DTO\ProviderConfig;
use App\Components\RequestPipeline\DTO\RetryConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FallbackExecutorTest extends TestCase
{
    use RefreshDatabase;
    public function test_returns_null_when_no_fallback_config(): void
    {
        $resolver = $this->createMock(ProviderResolver::class);
        $assembler = $this->createMock(PromptAssembler::class);

        $executor = new FallbackExecutor($resolver, $assembler);

        $result = $executor->tryFallback(null, $this->makeParsedRequest(), fn () => null, 1);

        $this->assertNull($result);
    }

    public function test_returns_successful_fallback_response(): void
    {
        $resolver = $this->createMock(ProviderResolver::class);
        $resolver->method('resolve')->willReturn(
            new ResolvedProvider('openai', 'gpt-4o', 'https://api.openai.com', 'key'),
        );

        $assembler = $this->createMock(PromptAssembler::class);
        $assembler->method('assemble')->willReturn(new AssembledPayload([], []));

        $executor = new FallbackExecutor($resolver, $assembler);
        $fallbackConfig = new ProviderConfig('openai', 'gpt-4o', null);

        $successResponse = new RawProviderResponse(200, ['choices' => []], [], 100);

        $client = \App\Models\ApiClient::factory()->create();
        $requestLog = \App\Models\RequestLog::factory()->create(['api_client_id' => $client->id]);

        $result = $executor->tryFallback(
            $fallbackConfig,
            $this->makeParsedRequest(),
            fn () => $successResponse,
            $requestLog->id,
        );

        $this->assertNotNull($result);
        $this->assertTrue($result->isSuccess());
    }

    private function makeParsedRequest(): ParsedRequest
    {
        return new ParsedRequest(
            version: '3.0',
            meta: new MetaData('req_001', null, null, null, null, null, null, []),
            provider: null,
            blocks: [new PromptBlock('instruction', 'user', null, null, null, null, null, null, false, 'Hello')],
            tools: null,
            parameters: null,
            callback: new CallbackConfig('https://example.com/cb', 'POST', [], 300, new RetryConfig(3, 'exponential', 1)),
            rawPromptXml: '<prompt/>',
            rawToolsXml: null,
            rawParametersXml: null,
        );
    }
}
