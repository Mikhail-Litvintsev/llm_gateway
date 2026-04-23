<?php

declare(strict_types=1);

namespace Tests\Feature\Files;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FilesNotFoundTest extends TestCase
{
    use RefreshDatabase;

    private string $rawKey;

    protected function setUp(): void
    {
        parent::setUp();

        $hasher = app(KeyHasher::class);
        $generator = app(KeyGenerator::class);

        $workspace = ClaudeWorkspace::create([
            'name' => 'files-404-test-workspace',
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        $this->rawKey = $generator->generateRawKey();

        Client::create([
            'name' => 'files-404-test',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($this->rawKey),
            'api_key_prefix' => $generator->derivePrefix($this->rawKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_initial'),
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
        ]);
    }

    #[Test]
    public function get_non_existing_file_returns_404_with_anthropic_body(): void
    {
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->rawKey}"])
            ->getJson('/api/v1/files/file_abcdefghijklmnopqrstuvwx');

        $response->assertStatus(404);
        $response->assertExactJson([
            'type' => 'error',
            'error' => [
                'type' => 'not_found_error',
                'message' => 'File not found',
            ],
        ]);
    }

    #[Test]
    public function delete_non_existing_file_returns_404_with_anthropic_body(): void
    {
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->rawKey}"])
            ->deleteJson('/api/v1/files/file_abcdefghijklmnopqrstuvwx');

        $response->assertStatus(404);
        $response->assertExactJson([
            'type' => 'error',
            'error' => [
                'type' => 'not_found_error',
                'message' => 'File not found',
            ],
        ]);
    }
}
