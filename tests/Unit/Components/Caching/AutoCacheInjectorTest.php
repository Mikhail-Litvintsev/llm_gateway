<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Caching;

use App\Components\Caching\AutoCacheInjector;
use App\Components\Caching\Enums\CacheInjectionOutcome;
use App\Models\Client;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AutoCacheInjectorTest extends TestCase
{
    private AutoCacheInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->injector = new AutoCacheInjector;

        config([
            'llm.claude.caching.estimation_chars_per_token' => 3.5,
            'llm.claude.caching.minimum_prefix_tokens' => [
                'opus' => 1024,
                'sonnet' => 1024,
                'haiku' => 2048,
            ],
        ]);
    }

    #[Test]
    public function skipped_disabled_when_feature_not_enabled(): void
    {
        $client = $this->makeClient(features: []);
        $payload = ['messages' => [['role' => 'user', 'content' => 'hi']]];

        $result = $this->injector->inject($payload, 'claude-sonnet-4-6', $client);

        $this->assertSame(CacheInjectionOutcome::SkippedDisabled, $result->outcome);
        $this->assertSame($payload, $result->payload);
    }

    #[Test]
    public function skipped_already_present_when_cache_control_in_payload(): void
    {
        $client = $this->makeClient(features: ['auto_cache_injection' => true]);
        $payload = [
            'cache_control' => ['type' => 'ephemeral'],
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ];

        $result = $this->injector->inject($payload, 'claude-sonnet-4-6', $client);

        $this->assertSame(CacheInjectionOutcome::SkippedAlreadyPresent, $result->outcome);
    }

    #[Test]
    public function skipped_already_present_when_cache_control_in_system_block(): void
    {
        $client = $this->makeClient(features: ['auto_cache_injection' => true]);
        $payload = [
            'system' => [
                ['type' => 'text', 'text' => 'You are helpful.', 'cache_control' => ['type' => 'ephemeral']],
            ],
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ];

        $result = $this->injector->inject($payload, 'claude-sonnet-4-6', $client);

        $this->assertSame(CacheInjectionOutcome::SkippedAlreadyPresent, $result->outcome);
    }

    #[Test]
    public function skipped_already_present_when_cache_control_in_message_content(): void
    {
        $client = $this->makeClient(features: ['auto_cache_injection' => true]);
        $payload = [
            'messages' => [
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'hi', 'cache_control' => ['type' => 'ephemeral']],
                ]],
            ],
        ];

        $result = $this->injector->inject($payload, 'claude-sonnet-4-6', $client);

        $this->assertSame(CacheInjectionOutcome::SkippedAlreadyPresent, $result->outcome);
    }

    #[Test]
    public function skipped_prefix_too_short_when_below_minimum(): void
    {
        $client = $this->makeClient(features: ['auto_cache_injection' => true]);
        $payload = [
            'system' => 'Short system prompt',
            'messages' => [
                ['role' => 'user', 'content' => 'hello'],
            ],
        ];

        $result = $this->injector->inject($payload, 'claude-sonnet-4-6', $client);

        $this->assertSame(CacheInjectionOutcome::SkippedPrefixTooShort, $result->outcome);
        $this->assertNotNull($result->estimatedPrefixTokens);
        $this->assertSame($payload, $result->payload);
    }

    #[Test]
    public function skipped_cap_exceeded_when_existing_markers_at_cap(): void
    {
        $client = $this->makeClient(features: [
            'auto_cache_injection' => true,
            'auto_cache_injection_max_breakpoints' => 1,
        ]);

        $longSystem = str_repeat('A', 5000);
        $payload = [
            'system' => [
                ['type' => 'text', 'text' => $longSystem, 'cache_control' => ['type' => 'ephemeral']],
            ],
            'messages' => [
                ['role' => 'user', 'content' => 'first'],
                ['role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'second'],
            ],
        ];

        $result = $this->injector->inject($payload, 'claude-sonnet-4-6', $client);

        $this->assertSame(CacheInjectionOutcome::SkippedAlreadyPresent, $result->outcome);
    }

    #[Test]
    public function skipped_cap_exceeded_when_markers_counted_separately(): void
    {
        $client = $this->makeClient(features: [
            'auto_cache_injection' => true,
            'auto_cache_injection_max_breakpoints' => 2,
        ]);

        $longText = str_repeat('B', 5000);
        $payload = [
            'system' => [$longText],
            'tools' => [
                ['name' => 't1', 'cache_control' => ['type' => 'ephemeral']],
                ['name' => 't2', 'cache_control' => ['type' => 'ephemeral']],
            ],
            'messages' => [
                ['role' => 'user', 'content' => 'first'],
                ['role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'second'],
            ],
        ];

        $result = $this->injector->inject($payload, 'claude-sonnet-4-6', $client);

        $this->assertSame(CacheInjectionOutcome::SkippedCapExceeded, $result->outcome);
    }

    #[Test]
    public function injected_when_conditions_met(): void
    {
        $client = $this->makeClient(features: [
            'auto_cache_injection' => true,
            'auto_cache_injection_max_breakpoints' => 4,
        ]);

        $longSystem = str_repeat('X', 5000);
        $payload = [
            'system' => $longSystem,
            'messages' => [
                ['role' => 'user', 'content' => 'hello'],
                ['role' => 'assistant', 'content' => 'hi'],
                ['role' => 'user', 'content' => 'bye'],
            ],
        ];

        $result = $this->injector->inject($payload, 'claude-sonnet-4-6', $client);

        $this->assertSame(CacheInjectionOutcome::Injected, $result->outcome);
        $this->assertArrayHasKey('cache_control', $result->payload);
        $this->assertSame(['type' => 'ephemeral'], $result->payload['cache_control']);
        $this->assertNotNull($result->estimatedPrefixTokens);
    }

    #[Test]
    public function haiku_model_requires_higher_minimum(): void
    {
        $client = $this->makeClient(features: ['auto_cache_injection' => true]);

        $mediumSystem = str_repeat('Y', 4000);
        $payload = [
            'system' => $mediumSystem,
            'messages' => [
                ['role' => 'user', 'content' => 'first'],
                ['role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'second'],
            ],
        ];

        $sonnetResult = $this->injector->inject($payload, 'claude-sonnet-4-6', $client);
        $haikuResult = $this->injector->inject($payload, 'claude-3-haiku', $client);

        $this->assertSame(CacheInjectionOutcome::Injected, $sonnetResult->outcome);
        $this->assertSame(CacheInjectionOutcome::SkippedPrefixTooShort, $haikuResult->outcome);
    }

    #[Test]
    public function prefix_chars_count_excludes_last_message(): void
    {
        $client = $this->makeClient(features: ['auto_cache_injection' => true]);

        $payload = [
            'messages' => [
                ['role' => 'user', 'content' => str_repeat('A', 5000)],
            ],
        ];

        $result = $this->injector->inject($payload, 'claude-sonnet-4-6', $client);

        $this->assertSame(CacheInjectionOutcome::SkippedPrefixTooShort, $result->outcome);
    }

    private function makeClient(array $features): Client
    {
        $client = new Client;
        $client->id = 1;
        $client->allowed_features = $features;

        return $client;
    }
}
