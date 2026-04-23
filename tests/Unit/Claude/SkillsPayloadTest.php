<?php

declare(strict_types=1);

namespace Tests\Unit\Claude;

use App\Components\Claude\Files\FilesRepository;
use App\Components\Claude\Payload\FileSourceResolver;
use App\Components\Claude\Payload\PayloadBuilder;
use App\Components\Claude\ToolTypeCatalog;
use App\Components\Routing\ModelResolver;
use App\Components\Validation\MessageRequestValidator;
use App\Components\Validation\ValidationContext;
use App\Models\Client;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SkillsPayloadTest extends TestCase
{
    private PayloadBuilder $builder;
    private MessageRequestValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new PayloadBuilder(
            new ModelResolver(),
            new FileSourceResolver($this->createMock(FilesRepository::class)),
            config('llm.claude.beta_headers'),
        );

        $this->validator = $this->app->make(MessageRequestValidator::class);
    }

    private function makeClient(array $features = []): Client
    {
        $client = new Client();
        $client->forceFill(['id' => 1, 'name' => 'test', 'api_key_hash' => 'h', 'allowed_features' => $features]);
        return $client;
    }

    #[Test]
    public function skills_with_code_execution_emits_payload_and_beta_header(): void
    {
        $client = $this->makeClient(['skills' => true, 'code_execution' => true]);

        $result = $this->builder->build([
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]]],
            'max_tokens' => 1024,
            'tools' => [['type' => ToolTypeCatalog::CODE_EXECUTION, 'name' => 'code_execution']],
            'skills' => [['type' => 'prebuilt', 'name' => 'xlsx']],
        ], $client);

        $this->assertArrayHasKey('skills', $result->decodedPayload);
        $this->assertContains('skills-2025-10-02', $result->betaHeaders);
    }

    #[Test]
    public function skills_without_code_execution_rejected(): void
    {
        $client = $this->makeClient(['skills' => true, 'code_execution' => true]);

        $result = $this->validator->validate([
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'max_tokens' => 1024,
            'skills' => [['type' => 'prebuilt', 'name' => 'xlsx']],
        ], ValidationContext::Sync, $client);

        $this->assertFalse($result->isValid());
        $this->assertSame('skills_require_code_execution', $result->errors[0]->code);
    }

    #[Test]
    public function skills_without_feature_rejected(): void
    {
        $client = $this->makeClient();

        $result = $this->validator->validate([
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'max_tokens' => 1024,
            'skills' => [['type' => 'prebuilt', 'name' => 'xlsx']],
        ], ValidationContext::Sync, $client);

        $this->assertFalse($result->isValid());
        $this->assertSame('skills_not_enabled', $result->errors[0]->code);
    }
}
