<?php

declare(strict_types=1);

namespace Tests\Unit\Components\RateLimiting\Claude;

use App\Components\RateLimiting\Claude\RateLimitHeaderParser;
use App\Components\RateLimiting\Claude\RateLimitNamespace;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('phase3-unit')]
final class RateLimitHeaderParserTest extends TestCase
{
    private RateLimitHeaderParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new RateLimitHeaderParser();
    }

    #[Test]
    public function messages_namespace_parses_correctly(): void
    {
        $headers = [
            'anthropic-ratelimit-requests-remaining' => '99',
            'anthropic-ratelimit-requests-limit' => '100',
            'anthropic-ratelimit-requests-reset' => '2026-04-12T12:00:00Z',
            'anthropic-ratelimit-input-tokens-remaining' => '90000',
            'anthropic-ratelimit-input-tokens-limit' => '100000',
            'anthropic-ratelimit-input-tokens-reset' => '2026-04-12T12:01:00Z',
            'anthropic-ratelimit-output-tokens-remaining' => '45000',
            'anthropic-ratelimit-output-tokens-limit' => '50000',
            'anthropic-ratelimit-output-tokens-reset' => '2026-04-12T12:02:00Z',
            'anthropic-ratelimit-tokens-remaining' => '180000',
            'anthropic-ratelimit-tokens-limit' => '200000',
            'anthropic-ratelimit-tokens-reset' => '2026-04-12T12:03:00Z',
        ];

        $result = $this->parser->parse($headers, RateLimitNamespace::Messages);

        $this->assertSame('99', $result['rpm_remaining']);
        $this->assertSame('100', $result['rpm_limit']);
        $this->assertSame('2026-04-12T12:00:00Z', $result['rpm_reset']);
        $this->assertSame('90000', $result['itpm_remaining']);
        $this->assertSame('100000', $result['itpm_limit']);
        $this->assertSame('2026-04-12T12:01:00Z', $result['itpm_reset']);
        $this->assertSame('45000', $result['otpm_remaining']);
        $this->assertSame('50000', $result['otpm_limit']);
        $this->assertSame('2026-04-12T12:02:00Z', $result['otpm_reset']);
        $this->assertSame('180000', $result['tokens_remaining']);
        $this->assertSame('200000', $result['tokens_limit']);
        $this->assertSame('2026-04-12T12:03:00Z', $result['tokens_reset']);
    }

    #[Test]
    public function batch_create_namespace_parses_correctly(): void
    {
        $headers = [
            'anthropic-ratelimit-batches-remaining' => '9',
            'anthropic-ratelimit-batches-limit' => '10',
            'anthropic-ratelimit-batches-reset' => '2026-04-12T13:00:00Z',
            'anthropic-ratelimit-batches-queue-remaining' => '450000',
            'anthropic-ratelimit-batches-queue-reset' => '2026-04-12T13:01:00Z',
        ];

        $result = $this->parser->parse($headers, RateLimitNamespace::BatchCreate);

        $this->assertSame('9', $result['rpm_remaining']);
        $this->assertSame('10', $result['rpm_limit']);
        $this->assertSame('2026-04-12T13:00:00Z', $result['rpm_reset']);
        $this->assertSame('450000', $result['queue_remaining']);
        $this->assertSame('2026-04-12T13:01:00Z', $result['queue_reset']);
    }

    #[Test]
    public function mismatched_namespace_returns_nulls(): void
    {
        $batchHeaders = [
            'anthropic-ratelimit-batches-remaining' => '9',
            'anthropic-ratelimit-batches-limit' => '10',
        ];

        $result = $this->parser->parse($batchHeaders, RateLimitNamespace::Messages);

        $this->assertNull($result['rpm_remaining']);
        $this->assertNull($result['rpm_limit']);
        $this->assertNull($result['itpm_remaining']);
        $this->assertNull($result['otpm_remaining']);
    }

    #[Test]
    public function case_insensitive_header_names(): void
    {
        $headers = [
            'Anthropic-RateLimit-Requests-Remaining' => '50',
            'ANTHROPIC-RATELIMIT-REQUESTS-LIMIT' => '100',
        ];

        $result = $this->parser->parse($headers, RateLimitNamespace::Messages);

        $this->assertSame('50', $result['rpm_remaining']);
        $this->assertSame('100', $result['rpm_limit']);
    }

    #[Test]
    public function array_header_values_use_first_element(): void
    {
        $headers = [
            'anthropic-ratelimit-requests-remaining' => ['50', '60'],
        ];

        $result = $this->parser->parse($headers, RateLimitNamespace::Messages);

        $this->assertSame('50', $result['rpm_remaining']);
    }
}
