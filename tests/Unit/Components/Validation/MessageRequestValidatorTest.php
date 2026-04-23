<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Validation;

use App\Components\Validation\DTO\ValidationResult;
use App\Components\Validation\MessageRequestValidator;
use App\Components\Validation\ValidationContext;
use App\Models\Client;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MessageRequestValidatorTest extends TestCase
{
    private MessageRequestValidator $validator;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = $this->app->make(MessageRequestValidator::class);

        $this->client = new Client;
        $this->client->forceFill([
            'id' => 1,
            'name' => 'test-client',
            'api_key_hash' => 'hash',
            'allowed_features' => [],
        ]);
    }

    private function minimalPayload(array $overrides = []): array
    {
        return array_merge([
            'model' => 'claude-sonnet',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
            'max_tokens' => 1024,
        ], $overrides);
    }

    private function validate(array $payload, ValidationContext $ctx = ValidationContext::Sync): ValidationResult
    {
        return $this->validator->validate($payload, $ctx, $this->client);
    }

    private function assertHasError(ValidationResult $result, string $code): void
    {
        $codes = array_map(static fn ($e) => $e->code, $result->errors);
        $this->assertContains($code, $codes, "Expected error code '$code' not found. Got: ".implode(', ', $codes));
    }

    private function assertNoErrorCode(ValidationResult $result, string $code): void
    {
        $codes = array_map(static fn ($e) => $e->code, $result->errors);
        $this->assertNotContains($code, $codes);
    }

    #[Test]
    public function messages_required(): void
    {
        $result = $this->validate(['model' => 'claude-sonnet']);

        $this->assertFalse($result->isValid());
        $this->assertHasError($result, 'messages_required');
    }

    #[Test]
    public function model_required(): void
    {
        $result = $this->validate([
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertHasError($result, 'model_required');
    }

    #[Test]
    public function unknown_model_alias_rejected(): void
    {
        $result = $this->validate($this->minimalPayload(['model' => 'gpt-4o']));

        $this->assertFalse($result->isValid());
        $this->assertHasError($result, 'unknown_model_alias');
    }

    #[Test]
    public function valid_model_alias_accepted(): void
    {
        $result = $this->validate($this->minimalPayload());

        $this->assertNoErrorCode($result, 'unknown_model_alias');
        $this->assertNoErrorCode($result, 'model_required');
    }

    #[Test]
    public function precheck_stops_early_on_missing_messages(): void
    {
        $result = $this->validate([]);

        $this->assertCount(1, $result->errors);
        $this->assertHasError($result, 'messages_required');
    }

    #[Test]
    public function stream_forbidden_in_batch_context(): void
    {
        $result = $this->validate(
            $this->minimalPayload(['stream' => true]),
            ValidationContext::BatchItem,
        );

        $this->assertHasError($result, 'stream_forbidden_in_batch_item');
    }

    #[Test]
    public function stream_forbidden_in_count_tokens_context(): void
    {
        $result = $this->validate(
            $this->minimalPayload(['stream' => true]),
            ValidationContext::CountTokens,
        );

        $this->assertHasError($result, 'stream_forbidden_in_count_tokens');
    }

    #[Test]
    public function stream_required_in_sync_stream_context(): void
    {
        $result = $this->validate(
            $this->minimalPayload(),
            ValidationContext::SyncStream,
        );

        $this->assertHasError($result, 'stream_required');
    }

    #[Test]
    public function stream_true_accepted_in_sync_stream_context(): void
    {
        $result = $this->validate(
            $this->minimalPayload(['stream' => true]),
            ValidationContext::SyncStream,
        );

        $this->assertNoErrorCode($result, 'stream_required');
    }

    #[Test]
    public function opus_prefill_rejected(): void
    {
        $result = $this->validate(
            $this->minimalPayload([
                'model' => 'claude-opus',
                'messages' => [
                    ['role' => 'user', 'content' => 'Hello'],
                    ['role' => 'assistant', 'content' => 'Let me think'],
                ],
            ]),
        );

        $this->assertHasError($result, 'opus_prefill_not_supported');
    }

    #[Test]
    public function sonnet_prefill_allowed(): void
    {
        $result = $this->validate(
            $this->minimalPayload([
                'model' => 'claude-sonnet',
                'messages' => [
                    ['role' => 'user', 'content' => 'Hello'],
                    ['role' => 'assistant', 'content' => 'Let me think'],
                ],
            ]),
        );

        $this->assertNoErrorCode($result, 'opus_prefill_not_supported');
    }

    #[Test]
    public function max_tokens_exceeds_batch_limit(): void
    {
        $result = $this->validate(
            $this->minimalPayload([
                'model' => 'claude-haiku',
                'max_tokens' => 100_000,
            ]),
            ValidationContext::BatchItem,
        );

        $this->assertHasError($result, 'max_tokens_exceeds_batch_limit');
    }

    #[Test]
    public function max_tokens_within_batch_limit_accepted(): void
    {
        $result = $this->validate(
            $this->minimalPayload([
                'model' => 'claude-haiku',
                'max_tokens' => 50_000,
            ]),
            ValidationContext::BatchItem,
        );

        $this->assertNoErrorCode($result, 'max_tokens_exceeds_batch_limit');
    }

    #[Test]
    public function thinking_budget_exceeds_max_output(): void
    {
        $result = $this->validate(
            $this->minimalPayload([
                'model' => 'claude-sonnet',
                'thinking' => ['type' => 'enabled', 'budget_tokens' => 200_000],
            ]),
        );

        $this->assertHasError($result, 'thinking_budget_exceeds_max_output');
    }

    #[Test]
    public function session_context_accepts_model_and_system_in_payload(): void
    {
        $result = $this->validate(
            $this->minimalPayload([
                'system' => 'You are a helper',
            ]),
            ValidationContext::Session,
        );

        $this->assertNoErrorCode($result, 'field_overridden_by_session');
    }

    #[Test]
    public function ptc_user_message_must_contain_only_tool_results(): void
    {
        $result = $this->validate(
            $this->minimalPayload([
                'messages' => [
                    ['role' => 'user', 'content' => 'Use the calculator'],
                    [
                        'role' => 'assistant',
                        'content' => [
                            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'calc', 'input' => ['expr' => '2+2']],
                        ],
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => 'Wrong block type'],
                        ],
                    ],
                ],
            ]),
        );

        $this->assertHasError($result, 'ptc_user_message_must_be_tool_results_only');
    }

    #[Test]
    public function ptc_valid_tool_result_accepted(): void
    {
        $result = $this->validate(
            $this->minimalPayload([
                'messages' => [
                    ['role' => 'user', 'content' => 'Use the calculator'],
                    [
                        'role' => 'assistant',
                        'content' => [
                            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'calc', 'input' => ['expr' => '2+2']],
                        ],
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '4'],
                        ],
                    ],
                ],
            ]),
        );

        $this->assertNoErrorCode($result, 'ptc_user_message_must_be_tool_results_only');
    }

    #[Test]
    public function ptc_string_content_after_tool_use_rejected(): void
    {
        $result = $this->validate(
            $this->minimalPayload([
                'messages' => [
                    ['role' => 'user', 'content' => 'Hi'],
                    [
                        'role' => 'assistant',
                        'content' => [
                            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'calc', 'input' => []],
                        ],
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Just a string',
                    ],
                ],
            ]),
        );

        $this->assertHasError($result, 'ptc_user_message_must_be_tool_results_only');
    }
}
