<?php

declare(strict_types=1);

namespace Tests\Unit\Claude;

use App\Components\Claude\DTO\ThinkingSpec;
use App\Components\Claude\Enums\ThinkingMode;
use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdaptiveThinkingValidatorTest extends TestCase
{
    #[Test]
    public function from_array_null_returns_off(): void
    {
        $spec = ThinkingSpec::fromArray(null);

        $this->assertSame(ThinkingMode::Off, $spec->mode);
    }

    #[Test]
    public function from_array_adaptive_parses_effort(): void
    {
        $spec = ThinkingSpec::fromArray(['type' => 'adaptive', 'effort' => 'high']);

        $this->assertSame(ThinkingMode::Adaptive, $spec->mode);
        $this->assertSame('high', $spec->effort);
    }

    #[Test]
    public function from_array_manual_parses_budget(): void
    {
        $spec = ThinkingSpec::fromArray(['type' => 'enabled', 'budget_tokens' => 8000]);

        $this->assertSame(ThinkingMode::Manual, $spec->mode);
        $this->assertSame(8000, $spec->budgetTokens);
    }

    #[Test]
    public function from_array_unknown_type_throws(): void
    {
        $this->expectException(PayloadBuildException::class);

        ThinkingSpec::fromArray(['type' => 'invalid']);
    }

    #[Test]
    public function is_enabled_for_adaptive(): void
    {
        $spec = ThinkingSpec::fromArray(['type' => 'adaptive', 'effort' => 'medium']);

        $this->assertTrue($spec->isEnabled());
    }

    #[Test]
    public function is_enabled_for_manual(): void
    {
        $spec = ThinkingSpec::fromArray(['type' => 'enabled', 'budget_tokens' => 5000]);

        $this->assertTrue($spec->isEnabled());
    }

    #[Test]
    public function is_not_enabled_for_off(): void
    {
        $spec = ThinkingSpec::fromArray(null);

        $this->assertFalse($spec->isEnabled());
    }
}
