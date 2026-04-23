<?php

declare(strict_types=1);

namespace Tests\Unit\Validation;

use App\Components\Validation\MessageRequestValidator;
use App\Components\Validation\ValidationContext;
use App\Models\Client;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FastModeValidatorTest extends TestCase
{
    private MessageRequestValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = $this->app->make(MessageRequestValidator::class);
    }

    private function makeClient(array $features = []): Client
    {
        $client = new Client;
        $client->forceFill(['id' => 1, 'name' => 'test', 'api_key_hash' => 'h', 'allowed_features' => $features]);

        return $client;
    }

    private function opusPayload(array $overrides = []): array
    {
        return array_merge([
            'model' => 'claude-opus',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'max_tokens' => 1024,
            'speed' => 'fast',
        ], $overrides);
    }

    #[Test]
    public function fast_without_feature_returns_403(): void
    {
        $result = $this->validator->validate(
            $this->opusPayload(),
            ValidationContext::Sync,
            $this->makeClient(),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('fast_mode_not_enabled', $result->errors[0]->code);
    }

    #[Test]
    public function fast_with_non_opus_model_returns_error(): void
    {
        $result = $this->validator->validate(
            array_merge($this->opusPayload(), ['model' => 'claude-sonnet']),
            ValidationContext::Sync,
            $this->makeClient(['fast_mode' => true]),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('fast_mode_model_unsupported', $result->errors[0]->code);
    }

    #[Test]
    public function fast_with_batch_returns_error(): void
    {
        $result = $this->validator->validate(
            $this->opusPayload(['stream' => false]),
            ValidationContext::BatchItem,
            $this->makeClient(['fast_mode' => true]),
        );

        $this->assertFalse($result->isValid());
        $errorCodes = array_map(fn ($e) => $e->code, $result->errors);
        $this->assertContains('fast_mode_batch_incompatible', $errorCodes);
    }

    #[Test]
    public function fast_with_priority_tier_returns_error(): void
    {
        $result = $this->validator->validate(
            $this->opusPayload(['service_tier' => 'auto']),
            ValidationContext::Sync,
            $this->makeClient(['fast_mode' => true, 'priority_tier' => true]),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('fast_mode_priority_incompatible', $result->errors[0]->code);
    }

    #[Test]
    public function fast_opus_with_feature_passes(): void
    {
        $result = $this->validator->validate(
            $this->opusPayload(),
            ValidationContext::Sync,
            $this->makeClient(['fast_mode' => true]),
        );

        $this->assertTrue($result->isValid());
    }
}
