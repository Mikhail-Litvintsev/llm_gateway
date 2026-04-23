<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class DropLegacyTablesTest extends TestCase
{
    private const array LEGACY_MODELS = [
        'App\\Models\\ApiClient',
        'App\\Models\\CallbackUrl',
        'App\\Models\\RequestLog',
        'App\\Models\\ResponseLog',
        'App\\Models\\RawResponse',
        'App\\Models\\PendingPrompt',
        'App\\Models\\PendingResponse',
        'App\\Models\\SessionHistory',
    ];

    #[Test]
    public function migration_throws_without_env_safeguard_when_legacy_tables_exist(): void
    {
        Schema::create('api_clients', function ($table) {
            $table->id();
        });

        $original = $_ENV['CLAUDE_ALLOW_LEGACY_DROP'] ?? null;
        $_ENV['CLAUDE_ALLOW_LEGACY_DROP'] = 'wrong-value';
        putenv('CLAUDE_ALLOW_LEGACY_DROP=wrong-value');

        $migration = require database_path('migrations/2026_05_01_000001_drop_legacy_tables.php');

        try {
            $migration->up();
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CLAUDE_ALLOW_LEGACY_DROP', $e->getMessage());
        } finally {
            Schema::dropIfExists('api_clients');
            if ($original !== null) {
                $_ENV['CLAUDE_ALLOW_LEGACY_DROP'] = $original;
                putenv("CLAUDE_ALLOW_LEGACY_DROP=$original");
            } else {
                unset($_ENV['CLAUDE_ALLOW_LEGACY_DROP']);
                putenv('CLAUDE_ALLOW_LEGACY_DROP');
            }
        }
    }

    #[Test]
    public function migration_skips_when_no_legacy_tables_exist(): void
    {
        $migration = require database_path('migrations/2026_05_01_000001_drop_legacy_tables.php');
        $migration->up();
        $this->assertTrue(true, 'Migration skipped without errors when no legacy tables exist');
    }

    #[Test]
    public function migration_runs_with_env_safeguard(): void
    {
        putenv('CLAUDE_ALLOW_LEGACY_DROP=yes-i-confirm-data-loss-2026-05');

        $migration = require database_path('migrations/2026_05_01_000001_drop_legacy_tables.php');
        $migration->up();

        putenv('CLAUDE_ALLOW_LEGACY_DROP');

        $this->assertTrue(true, 'Migration ran without errors');
    }

    #[Test]
    public function migration_preserves_failed_jobs_table(): void
    {
        $this->assertTrue(Schema::hasTable('failed_jobs'));
    }

    #[Test]
    public function migration_is_idempotent(): void
    {
        putenv('CLAUDE_ALLOW_LEGACY_DROP=yes-i-confirm-data-loss-2026-05');

        $migration = require database_path('migrations/2026_05_01_000001_drop_legacy_tables.php');
        $migration->up();
        $migration->up();

        putenv('CLAUDE_ALLOW_LEGACY_DROP');

        $this->assertTrue(true, 'Migration ran twice without errors');
    }

    #[Test]
    public function legacy_migration_files_removed(): void
    {
        $legacyFiles = glob(database_path('migrations/2024_01_01_*.php'));
        $this->assertEmpty($legacyFiles, 'Legacy 2024 migration files still exist: ' . implode(', ', $legacyFiles ?: []));

        $jobsFile = glob(database_path('migrations/2026_04_02_*_create_jobs_table.php'));
        $this->assertEmpty($jobsFile, 'Legacy create_jobs_table migration still exists');

        $retryFile = glob(database_path('migrations/2026_03_18_000001_*.php'));
        $this->assertEmpty($retryFile, 'Legacy add_retry_columns migration still exists');
    }

    #[Test]
    public function legacy_models_removed(): void
    {
        foreach (self::LEGACY_MODELS as $model) {
            $this->assertFalse(
                class_exists($model),
                "Legacy model {$model} still exists"
            );
        }
    }
}
