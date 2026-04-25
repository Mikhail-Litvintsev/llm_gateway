<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Monolog\Handler\NullHandler;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Force test environment BEFORE Laravel boots and RefreshDatabase runs migrate:fresh.
        // Docker env_file sets OS-level vars that override .env.testing and phpunit.xml,
        // so we must overwrite them at the process level.
        $this->forceEnv('DB_DATABASE', 'llm_gateway_test');
        $this->forceEnv('QUEUE_CONNECTION', 'sync');
        $this->forceEnv('CACHE_STORE', 'array');
        $this->forceEnv('LOG_CHANNEL', 'null');
        $this->forceEnv('SESSION_DRIVER', 'array');

        // Detect if running inside Docker (llm_mysql reachable) or on host (127.0.0.1:3307)
        if (gethostbyname('llm_mysql') !== 'llm_mysql') {
            $this->forceEnv('DB_HOST', 'llm_mysql');
            $this->forceEnv('DB_PORT', '3306');
        }

        parent::setUp();

        // Verify the guard worked
        $database = $this->app['config']['database.connections.mysql.database'];
        if ($database !== 'llm_gateway_test') {
            $this->fail("Tests are targeting '{$database}' instead of 'llm_gateway_test'. Aborting to protect production data.");
        }

        // Redirect the llm log channel to null in tests to avoid file permission issues
        config(['logging.channels.llm.driver' => 'monolog', 'logging.channels.llm.handler' => NullHandler::class]);

        // Avoid accidental rate limiting in tests that create clients with rate_limit_rpm=null.
        // Tests that exercise rate limiting override this back to a small number.
        config(['llm.rate_limit.default_per_minute' => 10_000]);
    }

    private function forceEnv(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
