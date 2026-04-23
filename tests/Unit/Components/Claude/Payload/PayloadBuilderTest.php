<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Payload;

use App\Components\Claude\Files\FilesRepository;
use App\Components\Claude\Payload\DTO\BuiltPayload;
use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use App\Components\Claude\Payload\FileSourceResolver;
use App\Components\Claude\Payload\PayloadBuilder;
use App\Components\Routing\ModelResolver;
use App\Models\Client;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PayloadBuilderTest extends TestCase
{
    private PayloadBuilder $builder;

    private Client $client;

    private array $betaHeaderMap = [
        'prompt_caching' => 'prompt-caching-2024-07-31',
        'extended_thinking' => 'extended-thinking-2025-04-01',
        'compaction' => 'compact-2026-01-12',
        'files_api' => 'files-api-2025-04-14',
        'output_300k' => 'output-300k-2026-03-24',
        'mcp_client' => 'mcp-client-2025-11-20',
        'computer_use' => 'computer-use-2025-01-24',
        'context_management' => 'context-management-2025-06-27',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new PayloadBuilder(
            new ModelResolver,
            new FileSourceResolver($this->createMock(FilesRepository::class)),
            $this->betaHeaderMap,
        );

        $this->client = new Client;
        $this->client->forceFill([
            'id' => 1,
            'name' => 'test-client',
            'api_key_hash' => 'hash',
            'allowed_features' => [],
        ]);
    }

    private function configureModel(string $alias, array $capabilityOverrides = []): void
    {
        $defaults = [
            'context_window' => 1_000_000,
            'max_output' => 64_000,
            'supports_thinking' => true,
            'supports_adaptive_thinking' => true,
            'supports_compaction' => true,
            'supports_prefill' => true,
        ];

        config()->set("llm.claude.model_capabilities.$alias", array_merge($defaults, $capabilityOverrides));
    }

    private function minimalPayload(array $overrides = []): array
    {
        return array_merge([
            'model' => 'claude-sonnet',
            'messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]],
            ],
            'max_tokens' => 1024,
        ], $overrides);
    }

    #[Test]
    public function happy_path_minimal_payload(): void
    {
        $this->configureModel('claude-sonnet');

        $result = $this->builder->build($this->minimalPayload(), $this->client);

        $this->assertInstanceOf(BuiltPayload::class, $result);
        $this->assertSame('claude-sonnet-4-6', $result->modelSnapshot);
        $this->assertSame('claude-sonnet', $result->modelAlias);
        $this->assertSame('claude-sonnet-4-6', $result->decodedPayload['model']);
        $this->assertSame(1024, $result->decodedPayload['max_tokens']);
        $this->assertGreaterThan(0, $result->payloadSizeBytes);
    }

    #[Test]
    public function model_alias_rewritten_to_snapshot(): void
    {
        $this->configureModel('claude-opus', ['max_output' => 128_000, 'supports_prefill' => false]);

        $result = $this->builder->build(
            $this->minimalPayload(['model' => 'claude-opus']),
            $this->client,
        );

        $this->assertSame('claude-opus-4-6', $result->decodedPayload['model']);
    }

    #[Test]
    public function rule1_max_tokens_exceeds_model_max_output(): void
    {
        $this->configureModel('claude-sonnet', ['max_output' => 64_000]);

        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('max_tokens (100000) exceeds model claude-sonnet maximum output (64000)');

        $this->builder->build(
            $this->minimalPayload(['max_tokens' => 100_000]),
            $this->client,
        );
    }

    #[Test]
    public function rule2_prefill_on_unsupported_model(): void
    {
        $this->configureModel('claude-opus', ['max_output' => 128_000, 'supports_prefill' => false]);

        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('does not support assistant prefill');

        $this->builder->build(
            $this->minimalPayload([
                'model' => 'claude-opus',
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]],
                    ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Let me']]],
                ],
            ]),
            $this->client,
        );
    }

    #[Test]
    public function rule3_thinking_on_unsupported_model(): void
    {
        $this->configureModel('claude-sonnet', ['supports_thinking' => false]);

        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('does not support extended thinking');

        $this->builder->build(
            $this->minimalPayload(['thinking' => ['type' => 'enabled', 'budget_tokens' => 1024]]),
            $this->client,
        );
    }

    #[Test]
    public function rule3_thinking_budget_exceeds_max_tokens_on_pre_46_model(): void
    {
        $this->configureModel('claude-sonnet', [
            'max_output' => 64_000,
            'supports_adaptive_thinking' => false,
        ]);

        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('budget_tokens (2048) must be less than max_tokens (1024)');

        $this->builder->build(
            $this->minimalPayload(['thinking' => ['type' => 'enabled', 'budget_tokens' => 2048]]),
            $this->client,
        );
    }

    #[Test]
    public function rule3_adaptive_thinking_on_unsupported_model(): void
    {
        $this->configureModel('claude-sonnet', [
            'supports_thinking' => true,
            'supports_adaptive_thinking' => false,
        ]);

        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('Adaptive thinking not supported');

        $this->builder->build(
            $this->minimalPayload(['thinking' => ['type' => 'adaptive']]),
            $this->client,
        );
    }

    #[Test]
    public function rule4_top_p_below_threshold_with_thinking(): void
    {
        $this->configureModel('claude-sonnet');

        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('top_p must be within [0.95, 1.0]');

        $this->builder->build(
            $this->minimalPayload([
                'thinking' => ['type' => 'enabled', 'budget_tokens' => 1024],
                'top_p' => 0.5,
            ]),
            $this->client,
        );
    }

    #[Test]
    public function rule4_top_p_at_threshold_with_thinking_accepted(): void
    {
        $this->configureModel('claude-sonnet');

        $result = $this->builder->build(
            $this->minimalPayload([
                'thinking' => ['type' => 'enabled', 'budget_tokens' => 1024],
                'top_p' => 0.95,
            ]),
            $this->client,
        );

        $this->assertSame(0.95, $result->decodedPayload['top_p']);
    }

    #[Test]
    public function rule5_temperature_excluded_when_thinking_enabled(): void
    {
        $this->configureModel('claude-sonnet');

        $result = $this->builder->build(
            $this->minimalPayload([
                'thinking' => ['type' => 'enabled', 'budget_tokens' => 1024],
                'temperature' => 0.7,
            ]),
            $this->client,
        );

        $this->assertArrayNotHasKey('temperature', $result->decodedPayload);
    }

    #[Test]
    public function rule6_citations_and_structured_output_mutually_exclusive(): void
    {
        $this->configureModel('claude-sonnet');

        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('Citations and structured output formats are mutually exclusive');

        $this->builder->build(
            $this->minimalPayload([
                'citations' => ['enabled' => true],
                'output_config' => ['format' => 'json_schema'],
            ]),
            $this->client,
        );
    }

    #[Test]
    public function rule7_payload_size_exceeds_32mb(): void
    {
        $this->configureModel('claude-sonnet');

        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('Payload exceeds maximum size of 32MB');

        $hugeContent = str_repeat('A', 33 * 1024 * 1024);

        $this->builder->build(
            $this->minimalPayload([
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => $hugeContent]]],
                ],
            ]),
            $this->client,
        );
    }

    #[Test]
    public function rule8_service_tier_priority_without_permission(): void
    {
        $this->configureModel('claude-sonnet');

        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('not authorized to use priority service tier');

        $this->builder->build(
            $this->minimalPayload(['service_tier' => 'priority']),
            $this->client,
        );
    }

    #[Test]
    public function rule8_service_tier_priority_with_permission(): void
    {
        $this->configureModel('claude-sonnet');
        $this->client->forceFill(['allowed_features' => ['priority_tier' => true]]);

        $result = $this->builder->build(
            $this->minimalPayload(['service_tier' => 'priority']),
            $this->client,
        );

        $this->assertSame('priority', $result->decodedPayload['service_tier']);
    }

    #[Test]
    public function rule9_inference_geo_not_allowed(): void
    {
        $this->configureModel('claude-sonnet');
        $this->client->forceFill(['inference_geo' => 'eu']);

        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage("Inference geo 'us' is not allowed");

        $this->builder->build(
            $this->minimalPayload(['inference_geo' => 'us']),
            $this->client,
        );
    }

    #[Test]
    public function rule9_inference_geo_override_allowed(): void
    {
        $this->configureModel('claude-sonnet');
        $this->client->forceFill([
            'inference_geo' => 'eu',
            'allowed_features' => ['inference_geo_override' => true],
        ]);

        $result = $this->builder->build(
            $this->minimalPayload(['inference_geo' => 'us']),
            $this->client,
        );

        $this->assertSame('us', $result->decodedPayload['inference_geo']);
    }

    #[Test]
    public function rule9_inference_geo_matches_client(): void
    {
        $this->configureModel('claude-sonnet');
        $this->client->forceFill(['inference_geo' => 'us']);

        $result = $this->builder->build(
            $this->minimalPayload(['inference_geo' => 'us']),
            $this->client,
        );

        $this->assertSame('us', $result->decodedPayload['inference_geo']);
    }

    #[Test]
    public function beta_header_collected_for_prompt_caching(): void
    {
        $this->configureModel('claude-sonnet');

        $result = $this->builder->build(
            $this->minimalPayload(['cache_control' => ['type' => 'ephemeral']]),
            $this->client,
        );

        $this->assertContains('prompt-caching-2024-07-31', $result->betaHeaders);
    }

    #[Test]
    public function beta_header_collected_for_extended_thinking(): void
    {
        $this->configureModel('claude-sonnet');

        $result = $this->builder->build(
            $this->minimalPayload(['thinking' => ['type' => 'enabled', 'budget_tokens' => 1024]]),
            $this->client,
        );

        $this->assertContains('extended-thinking-2025-04-01', $result->betaHeaders);
    }

    #[Test]
    public function beta_header_collected_for_output_300k(): void
    {
        $this->configureModel('claude-sonnet', ['max_output' => 300_000]);

        $result = $this->builder->build(
            $this->minimalPayload(['max_tokens' => 100_000]),
            $this->client,
        );

        $this->assertContains('output-300k-2026-03-24', $result->betaHeaders);
    }

    #[Test]
    public function beta_header_collected_for_mcp_servers(): void
    {
        $this->configureModel('claude-sonnet');

        $result = $this->builder->build(
            $this->minimalPayload([
                'mcp_servers' => [['type' => 'url', 'url' => 'https://mcp.example.com/sse', 'name' => 'test']],
                'tools' => [['type' => 'mcp_toolset', 'server_name' => 'test']],
            ]),
            $this->client,
        );

        $this->assertContains('mcp-client-2025-11-20', $result->betaHeaders);
    }

    #[Test]
    public function no_mcp_beta_header_without_mcp_servers(): void
    {
        $this->configureModel('claude-sonnet');

        $result = $this->builder->build(
            $this->minimalPayload(),
            $this->client,
        );

        $this->assertNotContains('mcp-client-2025-11-20', $result->betaHeaders);
    }

    #[Test]
    public function beta_header_collected_for_computer_use(): void
    {
        $this->configureModel('claude-sonnet');

        $result = $this->builder->build(
            $this->minimalPayload([
                'tools' => [['type' => 'computer_20250124', 'name' => 'computer', 'display_width_px' => 1024, 'display_height_px' => 768]],
            ]),
            $this->client,
        );

        $this->assertContains('computer-use-2025-01-24', $result->betaHeaders);
    }

    #[Test]
    public function beta_header_collected_for_files_api(): void
    {
        $this->configureModel('claude-sonnet');

        $result = $this->builder->build(
            $this->minimalPayload([
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'file', 'source' => ['type' => 'url', 'url' => 'https://example.com/doc.pdf']],
                        ],
                    ],
                ],
            ]),
            $this->client,
        );

        $this->assertContains('files-api-2025-04-14', $result->betaHeaders);
    }

    #[Test]
    public function beta_header_collected_for_compaction(): void
    {
        $this->configureModel('claude-sonnet', ['supports_compaction' => true]);

        $result = $this->builder->build(
            $this->minimalPayload(['system' => 'You are helpful']),
            $this->client,
        );

        $this->assertContains('compact-2026-01-12', $result->betaHeaders);
    }

    #[Test]
    public function no_beta_headers_for_plain_payload(): void
    {
        $this->configureModel('claude-sonnet', ['supports_compaction' => false]);

        $result = $this->builder->build($this->minimalPayload(), $this->client);

        $this->assertEmpty($result->betaHeaders);
    }

    #[Test]
    public function optional_fields_assembled_when_present(): void
    {
        $this->configureModel('claude-sonnet');

        $result = $this->builder->build(
            $this->minimalPayload([
                'system' => 'Be helpful',
                'temperature' => 0.7,
                'top_p' => 0.9,
                'top_k' => 40,
                'stop_sequences' => ['END'],
                'metadata' => ['user_id' => 'abc'],
            ]),
            $this->client,
        );

        $payload = $result->decodedPayload;
        $this->assertSame('Be helpful', $payload['system']);
        $this->assertSame(0.7, $payload['temperature']);
        $this->assertSame(0.9, $payload['top_p']);
        $this->assertSame(40, $payload['top_k']);
        $this->assertSame(['END'], $payload['stop_sequences']);
        $this->assertSame(['user_id' => 'abc'], $payload['metadata']);
    }

    #[Test]
    public function stream_flag_assembled(): void
    {
        $this->configureModel('claude-sonnet');

        $result = $this->builder->build(
            $this->minimalPayload(['stream' => true]),
            $this->client,
        );

        $this->assertTrue($result->decodedPayload['stream']);
    }

    #[Test]
    public function temperature_excluded_when_thinking_present(): void
    {
        $this->configureModel('claude-sonnet');

        $result = $this->builder->build(
            $this->minimalPayload(['thinking' => ['type' => 'enabled', 'budget_tokens' => 1024]]),
            $this->client,
        );

        $this->assertArrayNotHasKey('temperature', $result->decodedPayload);
    }

    #[Test]
    public function cache_control_in_messages_triggers_prompt_caching_beta(): void
    {
        $this->configureModel('claude-sonnet');

        $result = $this->builder->build(
            $this->minimalPayload([
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => 'Hello', 'cache_control' => ['type' => 'ephemeral']],
                        ],
                    ],
                ],
            ]),
            $this->client,
        );

        $this->assertContains('prompt-caching-2024-07-31', $result->betaHeaders);
    }

    #[Test]
    public function prefill_allowed_on_supported_model(): void
    {
        $this->configureModel('claude-sonnet', ['supports_prefill' => true]);

        $result = $this->builder->build(
            $this->minimalPayload([
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]],
                    ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Sure']]],
                ],
            ]),
            $this->client,
        );

        $this->assertSame('assistant', $result->decodedPayload['messages'][1]['role']);
    }

    #[Test]
    public function service_tier_standard_accepted_without_permission(): void
    {
        $this->configureModel('claude-sonnet');

        $result = $this->builder->build(
            $this->minimalPayload(['service_tier' => 'standard_only']),
            $this->client,
        );

        $this->assertSame('standard_only', $result->decodedPayload['service_tier']);
    }
}
