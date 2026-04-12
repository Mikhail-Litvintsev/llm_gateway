<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Beta;

use App\Components\Claude\Beta\BetaHeaderRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BetaHeaderRegistryTest extends TestCase
{
    private BetaHeaderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new BetaHeaderRegistry([
            'files_api' => 'files-api-2025-04-14',
            'compaction' => 'compact-2026-01-12',
            'fast_mode' => 'fast-mode-2026-02-01',
        ]);
    }

    #[Test]
    public function assembles_single_feature(): void
    {
        $this->assertSame('files-api-2025-04-14', $this->registry->assemble(['files_api']));
    }

    #[Test]
    public function assembles_multiple_features_in_order(): void
    {
        $this->assertSame(
            'compact-2026-01-12,files-api-2025-04-14',
            $this->registry->assemble(['compaction', 'files_api']),
        );
    }

    #[Test]
    public function unknown_feature_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown beta feature: nope');

        $this->registry->assemble(['nope']);
    }

    #[Test]
    public function empty_input_returns_empty_string(): void
    {
        $this->assertSame('', $this->registry->assemble([]));
    }
}
