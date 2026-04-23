<?php

declare(strict_types=1);

namespace Tests\Unit\Routing;

use App\Components\Routing\DTO\ModelCapabilities;
use App\Components\Routing\ModelResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ModelCapabilitiesLiveTest extends TestCase
{
    #[Test]
    public function get_capabilities_offline_returns_config_dto(): void
    {
        $resolver = new ModelResolver;

        $caps = $resolver->getCapabilities('claude-sonnet', false);

        $this->assertInstanceOf(ModelCapabilities::class, $caps);
        $this->assertNull($caps->fetchedAt);
    }

    #[Test]
    public function model_capabilities_from_config_maps_fields(): void
    {
        $caps = ModelCapabilities::fromConfig('claude-sonnet-4-6', [
            'context_window' => 1_000_000,
            'max_output' => 64_000,
            'supports_thinking' => true,
        ]);

        $this->assertSame('claude-sonnet-4-6', $caps->modelId);
        $this->assertSame(1_000_000, $caps->maxInputTokens);
        $this->assertSame(64_000, $caps->maxTokens);
        $this->assertTrue($caps->extendedThinking);
    }

    #[Test]
    public function model_capabilities_from_api_maps_fields(): void
    {
        $caps = ModelCapabilities::fromApi([
            'id' => 'claude-sonnet-4-6-20260101',
            'max_input_tokens' => 200_000,
            'max_tokens' => 64_000,
            'capabilities' => [
                'vision' => true,
                'tool_use' => true,
                'extended_thinking' => true,
                'prompt_caching' => true,
                'batch' => true,
                'citations' => false,
            ],
        ]);

        $this->assertSame('claude-sonnet-4-6-20260101', $caps->modelId);
        $this->assertSame(200_000, $caps->maxInputTokens);
        $this->assertTrue($caps->vision);
        $this->assertFalse($caps->citations);
        $this->assertNotNull($caps->fetchedAt);
    }

    #[Test]
    public function diff_detects_mismatches(): void
    {
        $config = ModelCapabilities::fromConfig('test', ['context_window' => 200_000, 'max_output' => 64_000, 'supports_thinking' => true]);
        $live = ModelCapabilities::fromApi([
            'id' => 'test',
            'max_input_tokens' => 200_000,
            'max_tokens' => 128_000,
            'capabilities' => ['vision' => true, 'tool_use' => true, 'extended_thinking' => true, 'prompt_caching' => true, 'batch' => true, 'citations' => true],
        ]);

        $drift = $config->diff($live);

        $this->assertArrayHasKey('maxTokens', $drift);
        $this->assertSame(64_000, $drift['maxTokens']['config']);
        $this->assertSame(128_000, $drift['maxTokens']['live']);
    }
}
