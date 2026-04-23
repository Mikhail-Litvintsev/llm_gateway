<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use App\Models\FileRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('phase3-feature')]
final class FilesLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $rawApiKey;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $generator = new KeyGenerator();
        $this->rawApiKey = $generator->generateRawKey();

        $hasher = $this->app->make(KeyHasher::class);

        $workspace = ClaudeWorkspace::create([
            'name' => 'test-workspace',
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test-key'),
            'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'files-lifecycle-client',
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
    public function show_file_returns_metadata(): void
    {
        $file = $this->createFileRecord();

        $response = $this->getJson("/api/v1/files/$file->file_id", [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertSame($file->file_id, $body['file_id']);
        $this->assertSame($file->anthropic_file_id, $body['anthropic_file_id']);
        $this->assertSame('test.pdf', $body['filename']);
        $this->assertSame('document', $body['upload_purpose']);
        $this->assertSame(1024, $body['size_bytes']);
    }

    #[Test]
    public function delete_file_returns_204_and_soft_deletes(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response('', 204),
        ]);

        $file = $this->createFileRecord();

        $response = $this->deleteJson("/api/v1/files/$file->file_id", [], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('files', [
            'id' => $file->id,
            'is_deleted' => true,
        ]);
    }

    #[Test]
    public function show_deleted_file_returns_404(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response('', 204),
        ]);

        $file = $this->createFileRecord();

        $this->deleteJson("/api/v1/files/$file->file_id", [], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ])->assertStatus(204);

        $response = $this->getJson("/api/v1/files/$file->file_id", [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function index_with_pagination_returns_cursor(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createFileRecord("file_" . Str::random(24));
        }

        $response = $this->getJson('/api/v1/files?limit=2', [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertCount(2, $body['files']);
        $this->assertNotNull($body['next_cursor']);

        $nextResponse = $this->getJson('/api/v1/files?limit=2&cursor=' . $body['next_cursor'], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $nextResponse->assertStatus(200);
        $nextBody = $nextResponse->json();
        $this->assertCount(1, $nextBody['files']);
        $this->assertNull($nextBody['next_cursor']);
    }

    #[Test]
    public function index_with_purpose_filter(): void
    {
        $this->createFileRecord('file_' . Str::random(24), 'document');
        $this->createFileRecord('file_' . Str::random(24), 'document');
        $this->createFileRecord('file_' . Str::random(24), 'vision');

        $response = $this->getJson('/api/v1/files?purpose=document', [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertCount(2, $body['files']);

        foreach ($body['files'] as $file) {
            $this->assertSame('document', $file['upload_purpose']);
        }
    }

    #[Test]
    public function show_nonexistent_file_returns_404(): void
    {
        $response = $this->getJson('/api/v1/files/file_000000000000000000000000', [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $response->assertStatus(404);
    }

    private function createFileRecord(
        ?string $fileId = null,
        string $purpose = 'document',
    ): FileRecord {
        $fileId ??= 'file_' . Str::random(24);

        $record = new FileRecord();
        $record->file_id = $fileId;
        $record->client_id = $this->client->id;
        $record->anthropic_file_id = 'anth_' . Str::random(16);
        $record->filename = 'test.pdf';
        $record->mime_type = $purpose === 'vision' ? 'image/png' : 'application/pdf';
        $record->size_bytes = 1024;
        $record->upload_purpose = $purpose;
        $record->is_deleted = false;
        $record->save();

        return $record;
    }
}
