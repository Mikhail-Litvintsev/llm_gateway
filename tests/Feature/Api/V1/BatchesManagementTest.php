<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Components\Claude\Enums\BatchItemStatus;
use App\Components\Claude\Enums\BatchStatus;
use App\Models\BatchItem;
use App\Models\BatchRecord;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('phase3-feature')]
final class BatchesManagementTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $rawApiKey;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $generator = new KeyGenerator;
        $this->rawApiKey = $generator->generateRawKey();

        $hasher = $this->app->make(KeyHasher::class);

        $workspace = ClaudeWorkspace::create([
            'name' => 'test-workspace',
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test-key'),
            'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'batch-mgmt-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($this->rawApiKey),
            'api_key_prefix' => $generator->derivePrefix($this->rawApiKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('test-signing-secret'),
            'allowed_features' => ['batches' => true],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    #[Test]
    public function show_in_progress_batch_returns_zero_counts(): void
    {
        $batch = $this->createBatch(BatchStatus::InProgress);

        $response = $this->getJson("/api/v1/batches/$batch->batch_id", [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertSame('in_progress', $body['status']);
        $this->assertSame(0, $body['counts']['succeeded']);
        $this->assertSame(0, $body['counts']['errored']);
    }

    #[Test]
    public function results_on_in_progress_returns_409(): void
    {
        $batch = $this->createBatch(BatchStatus::InProgress);

        $response = $this->getJson("/api/v1/batches/$batch->batch_id/results", [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(409);

        $body = $response->json();
        $this->assertSame('error', $body['type']);
        $this->assertSame('batch_not_ended', $body['error']['type']);
    }

    #[Test]
    public function results_on_ended_batch_returns_ndjson(): void
    {
        $batch = $this->createBatch(BatchStatus::Ended);

        BatchItem::insert([
            [
                'batch_id' => $batch->id,
                'custom_id' => 'r-1',
                'payload' => '{}',
                'status' => BatchItemStatus::Succeeded->value,
                'result_payload' => json_encode(['id' => 'msg_1', 'content' => [['type' => 'text', 'text' => 'ok']]]),
            ],
            [
                'batch_id' => $batch->id,
                'custom_id' => 'r-2',
                'payload' => '{}',
                'status' => BatchItemStatus::Succeeded->value,
                'result_payload' => json_encode(['id' => 'msg_2', 'content' => [['type' => 'text', 'text' => 'ok']]]),
            ],
        ]);

        $response = $this->getJson("/api/v1/batches/$batch->batch_id/results", [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/x-ndjson');

        $lines = array_filter(explode("\n", $response->streamedContent()));
        $this->assertCount(2, $lines);

        $first = json_decode($lines[0], true);
        $this->assertSame('r-1', $first['custom_id']);
        $this->assertSame('succeeded', $first['result']['type']);
    }

    #[Test]
    public function index_with_pagination_returns_cursor(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createBatch(BatchStatus::Ended);
        }

        $response = $this->getJson('/api/v1/batches?limit=2', [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertCount(2, $body['batches']);
        $this->assertNotNull($body['next_cursor']);

        $nextResponse = $this->getJson('/api/v1/batches?limit=2&cursor='.$body['next_cursor'], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $nextResponse->assertStatus(200);
        $nextBody = $nextResponse->json();
        $this->assertCount(1, $nextBody['batches']);
        $this->assertNull($nextBody['next_cursor']);
    }

    #[Test]
    public function delete_ended_batch_returns_204(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response('', 204),
        ]);

        $batch = $this->createBatch(BatchStatus::Ended, 'msgbatch_test123');

        $response = $this->deleteJson("/api/v1/batches/$batch->batch_id", [], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('batches', [
            'id' => $batch->id,
        ]);

        $batch->refresh();
        $this->assertNotNull($batch->deleted_at);
    }

    #[Test]
    public function delete_in_progress_batch_returns_409(): void
    {
        $batch = $this->createBatch(BatchStatus::InProgress);

        $response = $this->deleteJson("/api/v1/batches/$batch->batch_id", [], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(409);

        $body = $response->json();
        $this->assertSame('error', $body['type']);
        $this->assertSame('cannot_delete_in_flight_batch', $body['error']['type']);
    }

    #[Test]
    public function show_nonexistent_batch_returns_404(): void
    {
        $response = $this->getJson('/api/v1/batches/bat_000000000000000000000000', [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(404);
    }

    private function createBatch(BatchStatus $status, ?string $anthropicBatchId = null): BatchRecord
    {
        $batchId = 'bat_'.Str::random(24);

        $record = new BatchRecord;
        $record->batch_id = $batchId;
        $record->client_id = $this->client->id;
        $record->status = $status;
        $record->request_count = 2;
        $record->anthropic_batch_id = $anthropicBatchId;
        $record->save();

        return $record;
    }
}
