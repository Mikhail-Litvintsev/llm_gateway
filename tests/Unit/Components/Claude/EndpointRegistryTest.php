<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude;

use App\Components\Claude\EndpointRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EndpointRegistryTest extends TestCase
{
    private EndpointRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(EndpointRegistry::class);
    }

    #[Test]
    public function messages_returns_anthropic_messages_url(): void
    {
        $this->assertSame('https://api.anthropic.com/v1/messages', $this->registry->messages());
    }

    #[Test]
    public function count_tokens_returns_correct_url(): void
    {
        $this->assertSame('https://api.anthropic.com/v1/messages/count_tokens', $this->registry->countTokens());
    }

    #[Test]
    public function batches_returns_collection_url(): void
    {
        $this->assertSame('https://api.anthropic.com/v1/messages/batches', $this->registry->batches());
    }

    #[Test]
    public function batch_returns_individual_url(): void
    {
        $this->assertSame('https://api.anthropic.com/v1/messages/batches/btch_xyz', $this->registry->batch('btch_xyz'));
    }

    #[Test]
    public function batch_results_returns_results_url(): void
    {
        $this->assertSame('https://api.anthropic.com/v1/messages/batches/btch_xyz/results', $this->registry->batchResults('btch_xyz'));
    }
}
