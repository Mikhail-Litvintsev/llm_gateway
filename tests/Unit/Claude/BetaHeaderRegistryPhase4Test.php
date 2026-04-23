<?php

declare(strict_types=1);

namespace Tests\Unit\Claude;

use App\Components\Claude\Beta\BetaHeaderRegistry;
use App\Components\Claude\Payload\DTO\BuiltPayload;
use App\Components\Claude\ToolTypeCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BetaHeaderRegistryPhase4Test extends TestCase
{
    private BetaHeaderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new BetaHeaderRegistry([]);
    }

    #[Test]
    public function memory_tool_auto_injects_context_management(): void
    {
        $payload = $this->buildPayload(
            betaHeaders: [],
            serverToolTypes: [ToolTypeCatalog::MEMORY],
        );

        $result = $this->registry->assembleFromPayload($payload);

        $this->assertStringContainsString(ToolTypeCatalog::BETA_CONTEXT_MANAGEMENT, $result);
    }

    #[Test]
    public function computer_tool_produces_computer_use_header(): void
    {
        $payload = $this->buildPayload(
            betaHeaders: [],
            serverToolTypes: [ToolTypeCatalog::COMPUTER],
        );

        $result = $this->registry->assembleFromPayload($payload);

        $this->assertStringContainsString(ToolTypeCatalog::BETA_COMPUTER_USE, $result);
    }

    #[Test]
    public function no_duplicates_when_memory_and_compaction_both_present(): void
    {
        $payload = $this->buildPayload(
            betaHeaders: [ToolTypeCatalog::BETA_CONTEXT_MANAGEMENT],
            serverToolTypes: [ToolTypeCatalog::MEMORY],
        );

        $result = $this->registry->assembleFromPayload($payload);

        $count = substr_count($result, ToolTypeCatalog::BETA_CONTEXT_MANAGEMENT);
        $this->assertSame(1, $count);
    }

    #[Test]
    public function ordering_is_deterministic(): void
    {
        $payload = $this->buildPayload(
            betaHeaders: [ToolTypeCatalog::BETA_COMPACT],
            serverToolTypes: [ToolTypeCatalog::MEMORY, ToolTypeCatalog::COMPUTER],
        );

        $first = $this->registry->assembleFromPayload($payload);
        $second = $this->registry->assembleFromPayload($payload);

        $this->assertSame($first, $second);
    }

    private function buildPayload(array $betaHeaders, array $serverToolTypes): BuiltPayload
    {
        return new BuiltPayload(
            jsonBody: '{}',
            betaHeaders: $betaHeaders,
            modelSnapshot: 'claude-sonnet-4-6',
            modelAlias: 'sonnet',
            payloadSizeBytes: 2,
            decodedPayload: [],
            serverToolTypes: $serverToolTypes,
        );
    }
}
