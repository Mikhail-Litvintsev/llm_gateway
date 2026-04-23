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
final class FilesOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Client $clientA;

    private Client $clientB;

    private string $rawApiKeyA;

    private string $rawApiKeyB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $generator = new KeyGenerator;
        $hasher = $this->app->make(KeyHasher::class);

        $workspace = ClaudeWorkspace::create([
            'name' => 'test-workspace',
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test-key'),
            'is_active' => true,
        ]);

        $this->rawApiKeyA = $generator->generateRawKey();
        $this->clientA = Client::create([
            'name' => 'client-a',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($this->rawApiKeyA),
            'api_key_prefix' => $generator->derivePrefix($this->rawApiKeyA),
            'signing_secret_current_encrypted' => Crypt::encryptString('secret-a'),
            'allowed_features' => ['files' => true],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);

        $this->rawApiKeyB = $generator->generateRawKey();
        $this->clientB = Client::create([
            'name' => 'client-b',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($this->rawApiKeyB),
            'api_key_prefix' => $generator->derivePrefix($this->rawApiKeyB),
            'signing_secret_current_encrypted' => Crypt::encryptString('secret-b'),
            'allowed_features' => ['files' => true],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    #[Test]
    public function client_b_cannot_get_client_a_file_returns_404(): void
    {
        $file = $this->createFileForClient($this->clientA);

        $response = $this->getJson("/api/v1/files/$file->file_id", [
            'Authorization' => 'Bearer '.$this->rawApiKeyB,
        ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function client_b_cannot_delete_client_a_file_returns_404(): void
    {
        $file = $this->createFileForClient($this->clientA);

        $response = $this->deleteJson("/api/v1/files/$file->file_id", [], [
            'Authorization' => 'Bearer '.$this->rawApiKeyB,
        ]);

        $response->assertStatus(404);

        $this->assertDatabaseHas('files', [
            'id' => $file->id,
            'is_deleted' => false,
        ]);
    }

    #[Test]
    public function client_a_can_access_own_file(): void
    {
        $file = $this->createFileForClient($this->clientA);

        $response = $this->getJson("/api/v1/files/$file->file_id", [
            'Authorization' => 'Bearer '.$this->rawApiKeyA,
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertSame($file->file_id, $body['file_id']);
    }

    #[Test]
    public function client_b_index_does_not_show_client_a_files(): void
    {
        $this->createFileForClient($this->clientA);
        $this->createFileForClient($this->clientA);
        $clientBFile = $this->createFileForClient($this->clientB);

        $response = $this->getJson('/api/v1/files', [
            'Authorization' => 'Bearer '.$this->rawApiKeyB,
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertCount(1, $body['files']);
        $this->assertSame($clientBFile->file_id, $body['files'][0]['file_id']);
    }

    #[Test]
    public function delete_does_not_leak_existence_via_status_code(): void
    {
        $file = $this->createFileForClient($this->clientA);

        $responseGet = $this->getJson("/api/v1/files/$file->file_id", [
            'Authorization' => 'Bearer '.$this->rawApiKeyB,
        ]);

        $responseDelete = $this->deleteJson("/api/v1/files/$file->file_id", [], [
            'Authorization' => 'Bearer '.$this->rawApiKeyB,
        ]);

        $this->assertSame(404, $responseGet->getStatusCode());
        $this->assertSame(404, $responseDelete->getStatusCode());
    }

    private function createFileForClient(Client $client): FileRecord
    {
        $fileId = 'file_'.Str::random(24);

        $record = new FileRecord;
        $record->file_id = $fileId;
        $record->client_id = $client->id;
        $record->anthropic_file_id = 'anth_'.Str::random(16);
        $record->filename = 'owned-file.pdf';
        $record->mime_type = 'application/pdf';
        $record->size_bytes = 512;
        $record->upload_purpose = 'document';
        $record->is_deleted = false;
        $record->save();

        return $record;
    }
}
