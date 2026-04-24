<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Logging;

use App\Components\Logging\PayloadMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PayloadMasker audit (step_10):
 * - Used by Logging for request_payload / response_payload persisted to request_raw.
 * - Key-based masking only. Sensitive keys match /oauth|\btoken\b|secret|api[-_]?key|authorization/i.
 * - Whole-word \btoken\b avoids matching benign plural usage fields (input_tokens, output_tokens,
 *   cache_read_input_tokens, etc.) — critical for finalizeFromPersistedRaw which re-parses stored
 *   payloads and inserts usage counts into int columns.
 * - Known gaps left out of Phase 06 scope: value-based detection (sk-ant-*, gw_live_* embedded in
 *   free-form text), non-JSON raw input passes through unchanged.
 */
final class PayloadMaskerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sensitiveKeyProvider(): array
    {
        return [
            'oauth key' => ['{"oauth":"x"}', '{"oauth":"[REDACTED]"}'],
            'access_token key' => ['{"access_token":"x"}', '{"access_token":"[REDACTED]"}'],
            'secret key' => ['{"secret":"x"}', '{"secret":"[REDACTED]"}'],
            'api_key key' => ['{"api_key":"x"}', '{"api_key":"[REDACTED]"}'],
            'authorization key' => ['{"authorization":"Bearer abc"}', '{"authorization":"[REDACTED]"}'],
            'x-api-key key' => ['{"x-api-key":"sk-xxx"}', '{"x-api-key":"[REDACTED]"}'],
            'apikey key (no separator)' => ['{"apikey":"sk-xxx"}', '{"apikey":"[REDACTED]"}'],
        ];
    }

    #[Test]
    #[DataProvider('sensitiveKeyProvider')]
    public function mask_redacts_value_when_key_matches_sensitive_pattern(string $input, string $expected): void
    {
        $this->assertSame($expected, PayloadMasker::mask($input));
    }

    #[Test]
    public function mask_preserves_values_when_key_does_not_match(): void
    {
        $input = '{"user_name":"John","request_id":"req_abc","status":"ok"}';
        $this->assertSame($input, PayloadMasker::mask($input));
    }

    #[Test]
    public function mask_does_not_redact_anthropic_usage_token_fields(): void
    {
        $input = json_encode([
            'usage' => [
                'input_tokens' => 1234,
                'output_tokens' => 567,
                'cache_read_input_tokens' => 42,
                'cache_creation_input_tokens' => 0,
                'thinking_tokens' => 10,
            ],
        ]);

        $masked = PayloadMasker::mask($input);
        $decoded = json_decode($masked, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1234, $decoded['usage']['input_tokens']);
        $this->assertSame(567, $decoded['usage']['output_tokens']);
        $this->assertSame(42, $decoded['usage']['cache_read_input_tokens']);
        $this->assertSame(0, $decoded['usage']['cache_creation_input_tokens']);
        $this->assertSame(10, $decoded['usage']['thinking_tokens']);
    }

    #[Test]
    public function mask_recurses_into_nested_arrays(): void
    {
        $input = '{"outer":{"secret":"hidden","visible":"ok"}}';
        $expected = '{"outer":{"secret":"[REDACTED]","visible":"ok"}}';
        $this->assertSame($expected, PayloadMasker::mask($input));
    }

    #[Test]
    public function mask_recurses_into_list_of_objects(): void
    {
        $input = '{"items":[{"access_token":"a"},{"name":"b"}]}';
        $expected = '{"items":[{"access_token":"[REDACTED]"},{"name":"b"}]}';
        $this->assertSame($expected, PayloadMasker::mask($input));
    }

    #[Test]
    public function mask_returns_input_as_is_for_invalid_json(): void
    {
        $input = 'not json at all';
        $this->assertSame($input, PayloadMasker::mask($input));
    }

    #[Test]
    public function mask_passes_top_level_scalar_json_through(): void
    {
        $this->assertSame('"hello"', PayloadMasker::mask('"hello"'));
    }

    #[Test]
    public function mask_is_idempotent(): void
    {
        $input = '{"secret":"x","user":{"token":"y","name":"n"}}';
        $masked = PayloadMasker::mask($input);
        $double = PayloadMasker::mask($masked);
        $this->assertSame($masked, $double);
    }

    #[Test]
    public function mask_preserves_unescaped_slashes(): void
    {
        $input = '{"url":"https://example.com/path"}';
        $this->assertSame($input, PayloadMasker::mask($input));
    }

    #[Test]
    public function mask_preserves_unicode(): void
    {
        $input = '{"text":"привет"}';
        $this->assertSame($input, PayloadMasker::mask($input));
    }

    #[Test]
    public function mask_redacts_authorization_header_inside_nested_headers(): void
    {
        $input = '{"request":{"headers":{"authorization":"Bearer xxx","content-type":"application/json"}}}';
        $expected = '{"request":{"headers":{"authorization":"[REDACTED]","content-type":"application/json"}}}';
        $this->assertSame($expected, PayloadMasker::mask($input));
    }
}
