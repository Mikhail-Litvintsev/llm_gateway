<?php

declare(strict_types=1);

namespace Tests\Unit\Documentation;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DocsCodeReferencesTest extends TestCase
{
    #[Test]
    public function all_mentioned_components_exist(): void
    {
        $content = $this->readAllDocs();

        preg_match_all('/app\/Components\/(\w+)\//', $content, $matches);
        $components = array_unique($matches[1]);

        $missing = [];
        foreach ($components as $component) {
            $path = app_path("Components/{$component}");
            if (!is_dir($path)) {
                $missing[] = $component;
            }
        }

        $this->assertEmpty($missing, 'Referenced components not found: ' . implode(', ', $missing));
    }

    #[Test]
    public function all_mentioned_artisan_commands_exist(): void
    {
        $content = $this->readAllDocs();

        preg_match_all('/`((?:client|claude|llm|requests|webhook):[a-z-]+)`/', $content, $matches);
        $commands = array_unique($matches[1]);

        $registeredCommands = array_keys(Artisan::all());
        $missing = [];

        foreach ($commands as $command) {
            if (!in_array($command, $registeredCommands, true)) {
                $missing[] = $command;
            }
        }

        $this->assertEmpty($missing, 'Referenced commands not found: ' . implode(', ', $missing));
    }

    #[Test]
    public function all_mentioned_config_keys_exist(): void
    {
        $content = $this->readAllDocs();

        $topLevelKeys = [
            'model_aliases', 'model_capabilities', 'pricing', 'beta_headers',
            'caching', 'batch', 'thinking', 'skills', 'timeouts', 'webhook',
            'billing', 'dev_mode', 'queues', 'async', 'auth', 'claude',
            'rate_limit', 'service_tier', 'files', 'count_tokens', 'inference_geo',
            'max_request_payload_mb', 'max_batch_payload_mb', 'max_file_size_mb',
            'version', 'async_request_ttl_seconds', 'session_default_ttl_days',
            'raw_log_retention_days',
        ];

        $missing = [];
        foreach ($topLevelKeys as $key) {
            $configKey = "llm.{$key}";
            if (str_contains($content, $key) && config($configKey) === null) {
                if (in_array($key, ['claude'], true)) {
                    continue;
                }
                $nestedKey = "llm.claude.{$key}";
                if (config($nestedKey) === null) {
                    $missing[] = $key;
                }
            }
        }

        $this->assertEmpty($missing, 'Referenced config keys not found: ' . implode(', ', $missing));
    }

    private function readAllDocs(): string
    {
        $content = '';

        $claudeMd = base_path('CLAUDE.md');
        if (file_exists($claudeMd)) {
            $content .= file_get_contents($claudeMd) . "\n";
        }

        foreach (glob(base_path('documentation/*.md')) as $file) {
            $content .= file_get_contents($file) . "\n";
        }

        return $content;
    }
}
