<?php

declare(strict_types=1);

namespace Tests\Unit\Validation;

use App\Components\Validation\MessageRequestValidator;
use App\Components\Validation\ValidationContext;
use App\Models\Client;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ServiceTierValidatorTest extends TestCase
{
    private MessageRequestValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = $this->app->make(MessageRequestValidator::class);
        config(['llm.claude.service_tier.default' => 'standard_only']);
    }

    private function makeClient(array $features = []): Client
    {
        $client = new Client();
        $client->forceFill(['id' => 1, 'name' => 'test', 'api_key_hash' => 'h', 'allowed_features' => $features]);
        return $client;
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'max_tokens' => 1024,
        ], $overrides);
    }

    #[Test]
    public function auto_without_feature_returns_403(): void
    {
        $result = $this->validator->validate(
            $this->basePayload(['service_tier' => 'auto']),
            ValidationContext::Sync,
            $this->makeClient(),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('priority_tier_not_enabled', $result->errors[0]->code);
    }

    #[Test]
    public function auto_with_feature_passes(): void
    {
        $result = $this->validator->validate(
            $this->basePayload(['service_tier' => 'auto']),
            ValidationContext::Sync,
            $this->makeClient(['priority_tier' => true]),
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function standard_only_always_passes(): void
    {
        $result = $this->validator->validate(
            $this->basePayload(['service_tier' => 'standard_only']),
            ValidationContext::Sync,
            $this->makeClient(),
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function no_field_uses_config_default(): void
    {
        $result = $this->validator->validate(
            $this->basePayload(),
            ValidationContext::Sync,
            $this->makeClient(),
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function invalid_value_returns_error(): void
    {
        $result = $this->validator->validate(
            $this->basePayload(['service_tier' => 'turbo']),
            ValidationContext::Sync,
            $this->makeClient(),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('service_tier_invalid', $result->errors[0]->code);
    }
}
