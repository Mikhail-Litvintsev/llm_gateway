<?php

declare(strict_types=1);

namespace Tests\Unit\Claude;

use App\Components\Claude\Files\FilesRepository;
use App\Components\Claude\Payload\FileSourceResolver;
use App\Components\Claude\Payload\PayloadBuilder;
use App\Components\Claude\Response\ResponseParser;
use App\Components\Logging\PayloadMasker;
use App\Components\Routing\ModelResolver;
use App\Models\Client;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MCPConnectorPayloadTest extends TestCase
{
    private PayloadBuilder $builder;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new PayloadBuilder(
            new ModelResolver(),
            new FileSourceResolver($this->createMock(FilesRepository::class)),
            [
                'mcp_client' => 'mcp-client-2025-11-20',
                'files_api' => 'files-api-2025-04-14',
                'skills' => 'skills-2025-10-02',
                'fast_mode' => 'fast-mode-2026-02-01',
            ],
        );

        $this->client = new Client();
        $this->client->forceFill([
            'id' => 1,
            'name' => 'test',
            'api_key_hash' => 'hash',
            'allowed_features' => ['mcp_connector' => true],
        ]);

        $this->configureModel('claude-sonnet');
    }

    private function configureModel(string $alias): void
    {
        config([
            "llm.claude.model_aliases.$alias" => "$alias-4-6-20260101",
            "llm.claude.model_capabilities.$alias" => [
                'max_output' => 64000,
                'supports_thinking' => true,
                'supports_compaction' => true,
                'supports_prefill' => true,
            ],
        ]);
    }

    #[Test]
    public function mcp_servers_included_in_payload(): void
    {
        $result = $this->builder->build([
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]]],
            'max_tokens' => 1024,
            'mcp_servers' => [
                ['type' => 'url', 'url' => 'https://mcp.example.com/sse', 'name' => 'example', 'authorization_token' => 'Bearer xxx'],
            ],
            'tools' => [['type' => 'mcp_toolset', 'server_name' => 'example']],
        ], $this->client);

        $this->assertArrayHasKey('mcp_servers', $result->decodedPayload);
        $this->assertCount(1, $result->decodedPayload['mcp_servers']);
    }

    #[Test]
    public function mcp_beta_header_added(): void
    {
        $result = $this->builder->build([
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]]],
            'max_tokens' => 1024,
            'mcp_servers' => [
                ['type' => 'url', 'url' => 'https://mcp.example.com/sse', 'name' => 'example'],
            ],
        ], $this->client);

        $this->assertContains('mcp-client-2025-11-20', $result->betaHeaders);
    }

    #[Test]
    public function no_mcp_beta_header_without_mcp_servers(): void
    {
        $result = $this->builder->build([
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]]],
            'max_tokens' => 1024,
        ], $this->client);

        $this->assertNotContains('mcp-client-2025-11-20', $result->betaHeaders);
    }

    #[Test]
    public function payload_masker_redacts_authorization_token(): void
    {
        $payload = json_encode([
            'mcp_servers' => [
                ['type' => 'url', 'url' => 'https://mcp.example.com', 'name' => 'ex', 'authorization_token' => 'Bearer secret-token-123'],
            ],
        ]);

        $masked = PayloadMasker::mask($payload);
        $decoded = json_decode($masked, true);

        $this->assertSame('[REDACTED]', $decoded['mcp_servers'][0]['authorization_token']);
    }

    #[Test]
    public function response_parser_tags_mcp_tool_use_blocks(): void
    {
        $parser = new ResponseParser();

        $response = $parser->parseMessageResponse([
            'id' => 'msg_123',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => 'tool_use',
            'content' => [
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'example__search', 'input' => ['query' => 'test']],
                ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'regular_tool', 'input' => []],
            ],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ], []);

        $this->assertSame('example', $response->content[0]['mcp_server_name']);
        $this->assertArrayNotHasKey('mcp_server_name', $response->content[1]);
    }
}
