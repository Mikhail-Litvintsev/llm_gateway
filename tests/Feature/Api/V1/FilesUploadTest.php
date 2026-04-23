<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('phase3-feature')]
final class FilesUploadTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $rawApiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $generator = new KeyGenerator;
        $this->rawApiKey = $generator->generateRawKey();

        $hasher = $this->app->make(KeyHasher::class);

        $workspace = ClaudeWorkspace::create([
            'name' => 'test-workspace',
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test-key'),
            'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'files-upload-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($this->rawApiKey),
            'api_key_prefix' => $generator->derivePrefix($this->rawApiKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('test-signing-secret'),
            'allowed_features' => ['files' => true],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    #[Test]
    public function upload_pdf_with_document_purpose_returns_201(): void
    {
        Http::fake([
            'api.anthropic.com/v1/files' => Http::response(json_encode([
                'id' => 'file-anthropic-abc123',
                'filename' => 'test.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 1024,
            ]), 201),
        ]);

        $file = UploadedFile::fake()->create('test.pdf', 10, 'application/pdf');

        $response = $this->post('/api/v1/files', [
            'file' => $file,
            'purpose' => 'document',
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(201);

        $body = $response->json();
        $this->assertNotNull($body['file_id']);
        $this->assertSame('file-anthropic-abc123', $body['anthropic_file_id']);
        $this->assertSame('document', $body['upload_purpose']);

        $this->assertDatabaseHas('files', [
            'client_id' => $this->client->id,
            'anthropic_file_id' => 'file-anthropic-abc123',
            'upload_purpose' => 'document',
            'is_deleted' => false,
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.anthropic.com/v1/files')
                && $request->hasHeader('anthropic-beta', config('llm.claude.beta_headers.files_api'));
        });
    }

    #[Test]
    public function upload_png_with_vision_purpose_returns_201(): void
    {
        Http::fake([
            'api.anthropic.com/v1/files' => Http::response(json_encode([
                'id' => 'file-anthropic-img456',
                'filename' => 'image.png',
                'mime_type' => 'image/png',
                'size_bytes' => 2048,
            ]), 201),
        ]);

        $file = UploadedFile::fake()->createWithContent('image.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        $response = $this->post('/api/v1/files', [
            'file' => $file,
            'purpose' => 'vision',
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(201);

        $body = $response->json();
        $this->assertSame('vision', $body['upload_purpose']);

        $this->assertDatabaseHas('files', [
            'client_id' => $this->client->id,
            'upload_purpose' => 'vision',
        ]);
    }

    #[Test]
    public function upload_without_file_returns_400(): void
    {
        Http::fake();

        $response = $this->post('/api/v1/files', [
            'purpose' => 'document',
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(400);

        $body = $response->json();
        $this->assertSame('error', $body['type']);
        $this->assertSame('missing_file', $body['error']['type']);

        $this->assertDatabaseMissing('files', [
            'client_id' => $this->client->id,
        ]);
    }

    #[Test]
    public function upload_without_purpose_returns_400(): void
    {
        Http::fake();

        $file = UploadedFile::fake()->create('test.pdf', 10, 'application/pdf');

        $response = $this->post('/api/v1/files', [
            'file' => $file,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(400);

        $body = $response->json();
        $this->assertSame('error', $body['type']);
        $this->assertSame('invalid_purpose', $body['error']['type']);

        $this->assertDatabaseMissing('files', [
            'client_id' => $this->client->id,
        ]);
    }

    #[Test]
    public function upload_pdf_with_vision_purpose_returns_400_mime_not_allowed(): void
    {
        Http::fake();

        $file = UploadedFile::fake()->create('test.pdf', 10, 'application/pdf');

        $response = $this->post('/api/v1/files', [
            'file' => $file,
            'purpose' => 'vision',
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(400);

        $body = $response->json();
        $this->assertSame('error', $body['type']);
        $this->assertSame('mime_not_allowed_for_purpose', $body['error']['type']);

        Http::assertNothingSent();
    }

    #[Test]
    public function anthropic_401_returns_401_with_error_body(): void
    {
        $anthropicError = json_encode([
            'type' => 'error',
            'error' => ['type' => 'authentication_error', 'message' => 'Invalid API key'],
        ]);

        Http::fake([
            'api.anthropic.com/v1/files' => Http::response($anthropicError, 401),
        ]);

        $file = UploadedFile::fake()->create('test.pdf', 10, 'application/pdf');

        $response = $this->post('/api/v1/files', [
            'file' => $file,
            'purpose' => 'document',
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(401);

        $this->assertDatabaseMissing('files', [
            'client_id' => $this->client->id,
        ]);
    }
}
