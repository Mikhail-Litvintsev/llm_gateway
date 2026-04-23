<?php

namespace Tests\Unit\Components\ProviderGateway;

use App\Components\ProviderGateway\ProviderResolver;
use App\Components\RequestPipeline\DTO\ProviderConfig;
use App\Components\RequestPipeline\Exceptions\ValidationException;
use Tests\TestCase;

class ProviderResolverTest extends TestCase
{
    private ProviderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ProviderResolver();

        config([
            'llm.providers.claude' => [
                'endpoint' => 'https://api.anthropic.com/v1/messages',
                'api_key' => 'test-key',
                'default_model' => 'claude-sonnet-4-20250514',
            ],
            'llm.providers.openai' => [
                'endpoint' => 'https://api.openai.com/v1/chat/completions',
                'api_key' => 'test-key',
                'default_model' => 'gpt-4o',
            ],
        ]);
    }

    public function test_resolves_explicit_provider_and_model(): void
    {
        $config = new ProviderConfig('claude', 'claude-sonnet-4-20250514', null);

        $resolved = $this->resolver->resolve($config);

        $this->assertEquals('claude', $resolved->providerName);
        $this->assertEquals('claude-sonnet-4-20250514', $resolved->modelName);
    }

    public function test_detects_provider_from_model_name(): void
    {
        $config = new ProviderConfig(null, 'gpt-4o', null);

        $resolved = $this->resolver->resolve($config);

        $this->assertEquals('openai', $resolved->providerName);
        $this->assertEquals('gpt-4o', $resolved->modelName);
    }

    public function test_defaults_to_claude_when_nothing_specified(): void
    {
        $resolved = $this->resolver->resolve(null);

        $this->assertEquals('claude', $resolved->providerName);
        $this->assertEquals('claude-sonnet-4-20250514', $resolved->modelName);
    }

    public function test_throws_on_unknown_model(): void
    {
        $config = new ProviderConfig(null, 'unknown-model-xyz', null);

        $this->expectException(ValidationException::class);

        $this->resolver->resolve($config);
    }

    public function test_throws_on_unknown_provider(): void
    {
        $config = new ProviderConfig('nonexistent', null, null);

        $this->expectException(ValidationException::class);

        $this->resolver->resolve($config);
    }

    public function test_uses_default_model_when_only_provider_specified(): void
    {
        $config = new ProviderConfig('openai', null, null);

        $resolved = $this->resolver->resolve($config);

        $this->assertEquals('openai', $resolved->providerName);
        $this->assertEquals('gpt-4o', $resolved->modelName);
    }
}
