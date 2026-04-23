<?php

namespace Tests\Unit\Components\RateLimiter\Claude;

use App\Components\PromptAssembler\DTO\AssembledPayload;
use App\Components\RateLimiter\Claude\ClaudeTokenEstimator;
use PHPUnit\Framework\TestCase;

class ClaudeTokenEstimatorTest extends TestCase
{
    private ClaudeTokenEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estimator = new ClaudeTokenEstimator();
    }

    public function test_estimates_simple_text_message(): void
    {
        $payload = new AssembledPayload(
            body: [
                'messages' => [
                    ['role' => 'user', 'content' => str_repeat('Hello world ', 100)], // ~1200 chars
                ],
            ],
            headers: [],
        );

        $result = $this->estimator->estimate($payload);

        // ~1200 chars / 3.5 ≈ 343 tokens * 1.25 ≈ 429
        $this->assertGreaterThan(0, $result);
        $this->assertGreaterThan(300, $result);
        $this->assertLessThan(600, $result);
    }

    public function test_estimates_system_prompt_and_messages(): void
    {
        $payload = new AssembledPayload(
            body: [
                'system' => 'You are a helpful assistant.',
                'messages' => [
                    ['role' => 'user', 'content' => 'Tell me a joke.'],
                ],
            ],
            headers: [],
        );

        $result = $this->estimator->estimate($payload);

        $this->assertGreaterThan(0, $result);
        // system (~28 chars) + message (~15 chars) = ~43 chars / 3.5 ≈ 13 * 1.25 ≈ 17
        $this->assertGreaterThan(10, $result);
        $this->assertLessThan(50, $result);
    }

    public function test_estimates_with_tools(): void
    {
        $payload = new AssembledPayload(
            body: [
                'messages' => [
                    ['role' => 'user', 'content' => 'What is the weather?'],
                ],
                'tools' => [
                    [
                        'name' => 'get_weather',
                        'description' => 'Get weather for a city',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'city' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            headers: [],
        );

        $result = $this->estimator->estimate($payload);

        $this->assertGreaterThan(0, $result);
        // Should account for tools JSON
        $this->assertGreaterThan(30, $result);
    }

    public function test_estimates_array_content_blocks(): void
    {
        $payload = new AssembledPayload(
            body: [
                'system' => [
                    ['type' => 'text', 'text' => 'You are a helpful assistant.'],
                ],
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => 'Hello there!'],
                        ],
                    ],
                ],
            ],
            headers: [],
        );

        $result = $this->estimator->estimate($payload);

        $this->assertGreaterThan(0, $result);
        $this->assertGreaterThan(10, $result);
    }

    public function test_empty_payload_returns_zero(): void
    {
        $payload = new AssembledPayload(
            body: [],
            headers: [],
        );

        $result = $this->estimator->estimate($payload);

        $this->assertEquals(0, $result);
    }
}
