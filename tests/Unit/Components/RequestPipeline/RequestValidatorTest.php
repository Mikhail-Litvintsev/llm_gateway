<?php

namespace Tests\Unit\Components\RequestPipeline;

use App\Components\Auth\ApiAuthenticator;
use App\Components\Auth\DTO\AuthenticatedClient;
use App\Components\RequestPipeline\DTO\CallbackConfig;
use App\Components\RequestPipeline\DTO\GenerationParameters;
use App\Components\RequestPipeline\DTO\MetaData;
use App\Components\RequestPipeline\DTO\ParsedRequest;
use App\Components\RequestPipeline\DTO\PromptBlock;
use App\Components\RequestPipeline\DTO\ResponseFormatConfig;
use App\Components\RequestPipeline\DTO\RetryConfig;
use App\Components\RequestPipeline\Exceptions\ValidationException;
use App\Components\RequestPipeline\RequestValidator;
use App\Components\RequestPipeline\SessionTracker;
use PHPUnit\Framework\TestCase;

class RequestValidatorTest extends TestCase
{
    private RequestValidator $validator;
    private AuthenticatedClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $authenticator = $this->createMock(ApiAuthenticator::class);
        $sessionTracker = $this->createMock(SessionTracker::class);

        $this->validator = new RequestValidator($authenticator, $sessionTracker);

        $this->client = new AuthenticatedClient(
            id: 1,
            name: 'Test Client',
            rateLimit: 60,
            allowedProviders: null,
            signingSecret: 'test_secret',
            devMode: false,
        );
    }

    private function makeRequest(array $overrides = []): ParsedRequest
    {
        return new ParsedRequest(
            version: $overrides['version'] ?? '3.0',
            meta: $overrides['meta'] ?? new MetaData('req_001', null, null, null, null, null, null, []),
            provider: $overrides['provider'] ?? null,
            blocks: $overrides['blocks'] ?? [
                new PromptBlock('instruction', 'user', null, null, null, null, null, null, false, 'Hello'),
            ],
            tools: $overrides['tools'] ?? null,
            parameters: $overrides['parameters'] ?? null,
            callback: $overrides['callback'] ?? new CallbackConfig(
                'https://example.com/callback', 'POST', [], 300,
                new RetryConfig(3, 'exponential', 1),
            ),
            rawPromptXml: '<prompt/>',
            rawToolsXml: null,
            rawParametersXml: null,
        );
    }

    public function test_accepts_valid_minimal_request(): void
    {
        $request = $this->makeRequest();

        $this->validator->validate($request, $this->client);
        $this->assertTrue(true); // No exception thrown
    }

    public function test_rejects_missing_user_block(): void
    {
        $request = $this->makeRequest([
            'blocks' => [
                new PromptBlock('system', 'system', null, null, null, null, null, null, false, 'System prompt'),
            ],
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('role="user"');

        $this->validator->validate($request, $this->client);
    }

    public function test_rejects_invalid_type_role_combination(): void
    {
        $request = $this->makeRequest([
            'blocks' => [
                new PromptBlock('system', 'user', null, null, null, null, null, null, false, 'Wrong role'),
            ],
        ]);

        $this->expectException(ValidationException::class);

        $this->validator->validate($request, $this->client);
    }

    public function test_rejects_duplicate_block_ids(): void
    {
        $request = $this->makeRequest([
            'blocks' => [
                new PromptBlock('data', 'user', 'block_1', null, null, null, null, null, false, 'First'),
                new PromptBlock('data', 'user', 'block_1', null, null, null, null, null, false, 'Duplicate'),
            ],
        ]);

        $this->expectException(ValidationException::class);

        $this->validator->validate($request, $this->client);
    }

    public function test_rejects_dangling_description(): void
    {
        $request = $this->makeRequest([
            'blocks' => [
                new PromptBlock('instruction', 'user', null, null, null, null, null, null, false, 'Hello'),
                new PromptBlock('description', 'user', null, null, null, null, 'nonexistent_id', null, false, 'Dangling'),
            ],
        ]);

        $this->expectException(ValidationException::class);

        $this->validator->validate($request, $this->client);
    }

    public function test_rejects_orphan_description(): void
    {
        $request = $this->makeRequest([
            'blocks' => [
                new PromptBlock('instruction', 'user', null, null, null, null, null, null, false, 'Hello'),
                new PromptBlock('description', 'user', null, null, null, null, null, null, false, 'Orphan at end'),
            ],
        ]);

        $this->expectException(ValidationException::class);

        $this->validator->validate($request, $this->client);
    }

    public function test_rejects_history_order_violation(): void
    {
        $request = $this->makeRequest([
            'blocks' => [
                new PromptBlock('history', 'user', null, null, null, null, null, null, false, 'First'),
                new PromptBlock('history', 'user', null, null, null, null, null, null, false, 'Second user in row'),
                new PromptBlock('instruction', 'user', null, null, null, null, null, null, false, 'Current'),
            ],
        ]);

        $this->expectException(ValidationException::class);

        $this->validator->validate($request, $this->client);
    }

    public function test_rejects_insecure_callback_url(): void
    {
        $authenticator = $this->createMock(ApiAuthenticator::class);
        $authenticator->method('validateCallbackUrl')
            ->willThrowException(new ValidationException('INSECURE_CALLBACK_URL', 'Callback URL must use HTTPS.'));

        $sessionTracker = $this->createMock(SessionTracker::class);
        $validator = new RequestValidator($authenticator, $sessionTracker);

        $request = $this->makeRequest([
            'callback' => new CallbackConfig(
                'http://example.com/callback', 'POST', [], 300,
                new RetryConfig(3, 'exponential', 1),
            ),
        ]);

        $this->expectException(ValidationException::class);

        $validator->validate($request, $this->client);
    }

    public function test_rejects_invalid_temperature(): void
    {
        $request = $this->makeRequest([
            'parameters' => new GenerationParameters(
                temperature: 3.0,
                maxTokens: null,
                topP: null,
                topK: null,
                stopSequences: null,
                responseFormat: null,
                stream: false,
                reasoning: null,
                extra: [],
            ),
        ]);

        $this->expectException(ValidationException::class);

        $this->validator->validate($request, $this->client);
    }

    public function test_rejects_negative_max_tokens(): void
    {
        $request = $this->makeRequest([
            'parameters' => new GenerationParameters(
                temperature: null,
                maxTokens: -1,
                topP: null,
                topK: null,
                stopSequences: null,
                responseFormat: null,
                stream: false,
                reasoning: null,
                extra: [],
            ),
        ]);

        $this->expectException(ValidationException::class);

        $this->validator->validate($request, $this->client);
    }

    public function test_rejects_invalid_top_p(): void
    {
        $request = $this->makeRequest([
            'parameters' => new GenerationParameters(
                temperature: null,
                maxTokens: null,
                topP: 1.5,
                topK: null,
                stopSequences: null,
                responseFormat: null,
                stream: false,
                reasoning: null,
                extra: [],
            ),
        ]);

        $this->expectException(ValidationException::class);

        $this->validator->validate($request, $this->client);
    }

    public function test_rejects_too_many_stop_sequences(): void
    {
        $request = $this->makeRequest([
            'parameters' => new GenerationParameters(
                temperature: null,
                maxTokens: null,
                topP: null,
                topK: null,
                stopSequences: ['a', 'b', 'c', 'd', 'e'],
                responseFormat: null,
                stream: false,
                reasoning: null,
                extra: [],
            ),
        ]);

        $this->expectException(ValidationException::class);

        $this->validator->validate($request, $this->client);
    }

    public function test_rejects_missing_step_id_with_session(): void
    {
        $request = $this->makeRequest([
            'meta' => new MetaData('req_001', 'sess_001', null, null, null, null, null, []),
        ]);

        $this->expectException(ValidationException::class);

        $this->validator->validate($request, $this->client);
    }

    public function test_validates_response_format_type(): void
    {
        $request = $this->makeRequest([
            'parameters' => new GenerationParameters(
                temperature: null, maxTokens: null, topP: null, topK: null,
                stopSequences: null,
                responseFormat: new ResponseFormatConfig('invalid_type', null, null, null),
                stream: false, reasoning: null, extra: [],
            ),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('response_format.type must be one of');

        $this->validator->validate($request, $this->client);
    }

    public function test_json_schema_requires_schema_field(): void
    {
        $request = $this->makeRequest([
            'parameters' => new GenerationParameters(
                temperature: null, maxTokens: null, topP: null, topK: null,
                stopSequences: null,
                responseFormat: new ResponseFormatConfig('json_schema', 'test', true, null),
                stream: false, reasoning: null, extra: [],
            ),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('response_format.schema is required');

        $this->validator->validate($request, $this->client);
    }

    public function test_json_schema_requires_valid_json(): void
    {
        $request = $this->makeRequest([
            'parameters' => new GenerationParameters(
                temperature: null, maxTokens: null, topP: null, topK: null,
                stopSequences: null,
                responseFormat: new ResponseFormatConfig('json_schema', 'test', true, 'not-json'),
                stream: false, reasoning: null, extra: [],
            ),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('response_format.schema must be valid JSON');

        $this->validator->validate($request, $this->client);
    }

    public function test_json_schema_requires_name(): void
    {
        $request = $this->makeRequest([
            'parameters' => new GenerationParameters(
                temperature: null, maxTokens: null, topP: null, topK: null,
                stopSequences: null,
                responseFormat: new ResponseFormatConfig('json_schema', null, true, '{"type":"object"}'),
                stream: false, reasoning: null, extra: [],
            ),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('response_format.name is required');

        $this->validator->validate($request, $this->client);
    }

    public function test_json_schema_validates_name_pattern(): void
    {
        $request = $this->makeRequest([
            'parameters' => new GenerationParameters(
                temperature: null, maxTokens: null, topP: null, topK: null,
                stopSequences: null,
                responseFormat: new ResponseFormatConfig('json_schema', '123-invalid', true, '{"type":"object"}'),
                stream: false, reasoning: null, extra: [],
            ),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('response_format.name must match pattern');

        $this->validator->validate($request, $this->client);
    }

    public function test_json_schema_requires_type_property_in_schema(): void
    {
        $request = $this->makeRequest([
            'parameters' => new GenerationParameters(
                temperature: null, maxTokens: null, topP: null, topK: null,
                stopSequences: null,
                responseFormat: new ResponseFormatConfig('json_schema', 'test', true, '{"properties":{}}'),
                stream: false, reasoning: null, extra: [],
            ),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("'type' property");

        $this->validator->validate($request, $this->client);
    }

    public function test_accepts_valid_json_schema_response_format(): void
    {
        $request = $this->makeRequest([
            'parameters' => new GenerationParameters(
                temperature: null, maxTokens: null, topP: null, topK: null,
                stopSequences: null,
                responseFormat: new ResponseFormatConfig('json_schema', 'valid_schema', true, '{"type":"object","properties":{"action":{"type":"string"}}}'),
                stream: false, reasoning: null, extra: [],
            ),
        ]);

        $this->validator->validate($request, $this->client);
        $this->assertTrue(true);
    }
}
