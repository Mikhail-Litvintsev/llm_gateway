<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Payload\Validators;

use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use App\Components\Claude\Payload\Validators\PrefillCompatibilityEnforcer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PrefillCompatibilityEnforcerTest extends TestCase
{
    private PrefillCompatibilityEnforcer $enforcer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enforcer = new PrefillCompatibilityEnforcer;
    }

    #[Test]
    public function passes_when_last_message_user_role(): void
    {
        $this->enforcer->enforce(
            ['messages' => [['role' => 'user', 'content' => 'hi']]],
            ['supports_prefill' => false],
            'claude-haiku',
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function passes_when_last_message_assistant_and_model_supports_prefill(): void
    {
        $this->enforcer->enforce(
            ['messages' => [
                ['role' => 'user', 'content' => 'hi'],
                ['role' => 'assistant', 'content' => 'pre'],
            ]],
            ['supports_prefill' => true],
            'claude-sonnet',
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function throws_when_last_message_assistant_and_model_does_not_support_prefill(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('Model claude-haiku does not support assistant prefill');

        $this->enforcer->enforce(
            ['messages' => [
                ['role' => 'user', 'content' => 'hi'],
                ['role' => 'assistant', 'content' => 'pre'],
            ]],
            ['supports_prefill' => false],
            'claude-haiku',
        );
    }

    #[Test]
    public function passes_when_messages_empty(): void
    {
        $this->enforcer->enforce(
            ['messages' => []],
            ['supports_prefill' => false],
            'claude-haiku',
        );

        $this->expectNotToPerformAssertions();
    }
}
