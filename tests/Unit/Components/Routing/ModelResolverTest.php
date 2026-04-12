<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Routing;

use App\Components\Routing\Exceptions\UnknownModelAliasException;
use App\Components\Routing\ModelResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ModelResolverTest extends TestCase
{
    private ModelResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ModelResolver;
    }

    #[Test]
    public function resolves_known_alias_to_snapshot(): void
    {
        $result = $this->resolver->resolve('claude-sonnet');

        $this->assertSame('claude-sonnet-4-6', $result->snapshot);
    }

    #[Test]
    public function returns_capabilities_for_alias(): void
    {
        $result = $this->resolver->resolve('claude-sonnet');

        $this->assertSame(1_000_000, $result->capabilities['context_window']);
    }

    #[Test]
    public function returns_pricing_for_alias(): void
    {
        $result = $this->resolver->resolve('claude-sonnet');

        $this->assertSame(3.00, $result->pricing['input']);
    }

    #[Test]
    public function throws_on_unknown_alias(): void
    {
        $this->expectException(UnknownModelAliasException::class);

        $this->resolver->resolve('gpt-4');
    }

    #[Test]
    public function cache_key_includes_config_version(): void
    {
        $this->resolver->resolve('claude-sonnet');

        config()->set('llm.version', '999.0');
        config()->set('llm.claude.model_aliases.claude-sonnet', 'claude-sonnet-changed');

        $resolver2 = new ModelResolver;
        $result = $resolver2->resolve('claude-sonnet');

        $this->assertSame('claude-sonnet-changed', $result->snapshot);
    }
}
