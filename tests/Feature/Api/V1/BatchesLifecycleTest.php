<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Jobs\Claude\SubmitBatchToAnthropic;
use App\Models\BatchItem;
use App\Models\BatchRecord;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('phase3-feature')]
final class BatchesLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $rawApiKey;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Bus::fake();

        $generator = new KeyGenerator();
        $this->rawApiKey = $generator->generateRawKey();

        $hasher = $this->app->make(KeyHasher::class);
        $hash = $hasher->hash($this->rawApiKey);

        $workspace = ClaudeWorkspace::create([
            'name' => 'test-workspace',
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test-key'),
            'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'batch-lifecycle-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hash,
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
    public function create_batch_returns_201_and_persists_batch_with_items(): void
    {
        $items = $this->buildBatchItems(5);

        $response = $this->postJson('/api/v1/batches', [
            'requests' => $items,
            'callback_url' => 'https://example.com/callback',
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $response->assertStatus(201);

        $body = $response->json();
        $this->assertNotNull($body['batch_id']);
        $this->assertSame('created', $body['status']);
        $this->assertSame(5, $body['request_count']);

        $this->assertDatabaseHas('batches', [
            'client_id' => $this->client->id,
            'status' => 'created',
            'request_count' => 5,
        ]);

        $batchRecord = BatchRecord::where('client_id', $this->client->id)->first();
        $this->assertNotNull($batchRecord);

        $itemsCount = BatchItem::where('batch_id', $batchRecord->id)->count();
        $this->assertSame(5, $itemsCount);
    }

    #[Test]
    public function create_batch_dispatches_submit_job(): void
    {
        $items = $this->buildBatchItems(3);

        $this->postJson('/api/v1/batches', [
            'requests' => $items,
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ])->assertStatus(201);

        Bus::assertDispatched(SubmitBatchToAnthropic::class);
    }

    #[Test]
    public function create_batch_stores_callback_url(): void
    {
        $items = $this->buildBatchItems(2);

        $this->postJson('/api/v1/batches', [
            'requests' => $items,
            'callback_url' => 'https://example.com/webhook',
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ])->assertStatus(201);

        $this->assertDatabaseHas('batches', [
            'client_id' => $this->client->id,
            'callback_url' => 'https://example.com/webhook',
        ]);
    }

    #[Test]
    public function create_batch_with_empty_requests_returns_error(): void
    {
        $response = $this->postJson('/api/v1/batches', [
            'requests' => [],
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());

        $body = $response->json();
        $this->assertSame('error', $body['type']);
    }

    #[Test]
    public function create_batch_with_duplicate_custom_ids_returns_error(): void
    {
        $items = [
            $this->buildSingleItem('item-1'),
            $this->buildSingleItem('item-1'),
        ];

        $response = $this->postJson('/api/v1/batches', [
            'requests' => $items,
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());

        $body = $response->json();
        $this->assertSame('error', $body['type']);
        $this->assertStringContainsString('duplicate', $body['error']['message']);
    }

    #[Test]
    public function batch_items_have_pending_status_after_creation(): void
    {
        $items = $this->buildBatchItems(3);

        $this->postJson('/api/v1/batches', [
            'requests' => $items,
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ])->assertStatus(201);

        $batchRecord = BatchRecord::where('client_id', $this->client->id)->first();
        $pendingCount = BatchItem::where('batch_id', $batchRecord->id)
            ->where('status', 'pending')
            ->count();

        $this->assertSame(3, $pendingCount);
    }

    #[Test]
    public function unauthenticated_batch_create_returns_401(): void
    {
        $response = $this->postJson('/api/v1/batches', [
            'requests' => $this->buildBatchItems(1),
        ]);

        $response->assertStatus(401);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildBatchItems(int $count): array
    {
        $items = [];

        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->buildSingleItem("item-$i");
        }

        return $items;
    }

    private function buildSingleItem(string $customId): array
    {
        return [
            'custom_id' => $customId,
            'params' => [
                'model' => 'claude-sonnet',
                'max_tokens' => 1024,
                'messages' => [
                    ['role' => 'user', 'content' => 'Hello from batch item ' . $customId],
                ],
            ],
        ];
    }
}
