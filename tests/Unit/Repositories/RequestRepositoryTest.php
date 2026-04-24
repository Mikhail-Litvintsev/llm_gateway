<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Components\Logging\Enums\Endpoint;
use App\Components\Logging\Enums\Mode;
use App\Components\Logging\Enums\RequestStatus;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use App\Repositories\RequestRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RequestRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private RequestRepository $repo;

    private Client $client;

    private Client $otherClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = app(RequestRepository::class);
        $this->client = $this->seedClient('a');
        $this->otherClient = $this->seedClient('b');
    }

    #[Test]
    public function find_returns_null_when_request_missing(): void
    {
        $this->assertNull($this->repo->find('req_missing_xxxxxxxxxxxx'));
    }

    #[Test]
    public function find_returns_model_for_existing_request(): void
    {
        $this->seedRequest('req_aaaaaaaaaaaaaaaaaaaaaaaa', $this->client->id);
        $found = $this->repo->find('req_aaaaaaaaaaaaaaaaaaaaaaaa');

        $this->assertNotNull($found);
        $this->assertSame('req_aaaaaaaaaaaaaaaaaaaaaaaa', $found->request_id);
    }

    #[Test]
    public function find_for_client_filters_by_client_id(): void
    {
        $this->seedRequest('req_bbbbbbbbbbbbbbbbbbbbbbbb', $this->otherClient->id);

        $this->assertNotNull($this->repo->findForClient('req_bbbbbbbbbbbbbbbbbbbbbbbb', $this->otherClient->id));
        $this->assertNull($this->repo->findForClient('req_bbbbbbbbbbbbbbbbbbbbbbbb', $this->client->id));
    }

    #[Test]
    public function find_details_loads_request_usage_raw(): void
    {
        $this->seedRequest('req_dddddddddddddddddddddddd', $this->client->id);
        DB::table('request_usage')->insert([
            'request_id' => 'req_dddddddddddddddddddddddd',
            'input_tokens' => 10,
            'output_tokens' => 5,
            'cost_usd' => '0.00001000',
            'cost_breakdown' => '{}',
        ]);
        DB::table('request_raw')->insert([
            'request_id' => 'req_dddddddddddddddddddddddd',
            'request_payload' => '{}',
            'response_payload' => '{"ok":true}',
            'retention_until' => now()->addDays(14),
        ]);

        $details = $this->repo->findDetails('req_dddddddddddddddddddddddd', includeRaw: true);

        $this->assertTrue($details->exists());
        $this->assertNotNull($details->usage);
        $this->assertSame(10, $details->usage->input_tokens);
        $this->assertNotNull($details->raw);
    }

    #[Test]
    public function find_details_returns_empty_when_missing(): void
    {
        $details = $this->repo->findDetails('req_zzzzzzzzzzzzzzzzzzzzzzzz', includeRaw: true);

        $this->assertFalse($details->exists());
        $this->assertNull($details->request);
        $this->assertNull($details->usage);
        $this->assertNull($details->raw);
    }

    #[Test]
    public function find_details_skips_raw_when_not_requested(): void
    {
        $this->seedRequest('req_eeeeeeeeeeeeeeeeeeeeeeee', $this->client->id);
        DB::table('request_raw')->insert([
            'request_id' => 'req_eeeeeeeeeeeeeeeeeeeeeeee',
            'request_payload' => '{}',
            'response_payload' => '{}',
            'retention_until' => now()->addDays(14),
        ]);

        $details = $this->repo->findDetails('req_eeeeeeeeeeeeeeeeeeeeeeee', includeRaw: false);

        $this->assertTrue($details->exists());
        $this->assertNull($details->raw);
    }

    #[Test]
    public function get_status_returns_string_or_null(): void
    {
        $this->seedRequest('req_ffffffffffffffffffffffff', $this->client->id, RequestStatus::Accepted->value);

        $this->assertSame(RequestStatus::Accepted->value, $this->repo->getStatus('req_ffffffffffffffffffffffff'));
        $this->assertNull($this->repo->getStatus('req_missing_zzzzzzzzzzzz'));
    }

    #[Test]
    public function create_accepted_inserts_minimal_row(): void
    {
        $this->repo->createAccepted(
            'req_gggggggggggggggggggggggg',
            $this->client->id,
            Endpoint::Messages->value,
            Mode::AsyncCallback->value,
            'claude-sonnet',
            'claude-sonnet-4-6',
            RequestStatus::Accepted->value,
        );

        $row = DB::table('requests')->where('request_id', 'req_gggggggggggggggggggggggg')->first();
        $this->assertNotNull($row);
        $this->assertSame(RequestStatus::Accepted->value, $row->status);
    }

    #[Test]
    public function mark_in_progress_updates_status_and_started_at(): void
    {
        $this->seedRequest('req_hhhhhhhhhhhhhhhhhhhhhhhh', $this->client->id);
        $this->repo->markInProgress('req_hhhhhhhhhhhhhhhhhhhhhhhh', RequestStatus::InProgress->value);

        $row = DB::table('requests')->where('request_id', 'req_hhhhhhhhhhhhhhhhhhhhhhhh')->first();
        $this->assertSame(RequestStatus::InProgress->value, $row->status);
        $this->assertNotNull($row->started_at);
    }

    #[Test]
    public function mark_final_status_updates_completion(): void
    {
        $this->seedRequest('req_iiiiiiiiiiiiiiiiiiiiiiii', $this->client->id);
        $this->repo->markFinalStatus(
            'req_iiiiiiiiiiiiiiiiiiiiiiii',
            RequestStatus::FailedServerError->value,
            'async_job_failed',
            'crash',
        );

        $row = DB::table('requests')->where('request_id', 'req_iiiiiiiiiiiiiiiiiiiiiiii')->first();
        $this->assertSame(RequestStatus::FailedServerError->value, $row->status);
        $this->assertSame('async_job_failed', $row->error_type);
        $this->assertSame('crash', $row->error_message);
        $this->assertNotNull($row->completed_at);
    }

    #[Test]
    public function set_status_updates_only_status_without_touching_completed_at(): void
    {
        $this->seedRequest('req_jjjjjjjjjjjjjjjjjjjjjjjj', $this->client->id);
        $original = DB::table('requests')->where('request_id', 'req_jjjjjjjjjjjjjjjjjjjjjjjj')->first();

        $this->repo->setStatus('req_jjjjjjjjjjjjjjjjjjjjjjjj', RequestStatus::FailedCallbackDelivery->value);

        $row = DB::table('requests')->where('request_id', 'req_jjjjjjjjjjjjjjjjjjjjjjjj')->first();
        $this->assertSame(RequestStatus::FailedCallbackDelivery->value, $row->status);
        $this->assertSame($original->completed_at, $row->completed_at);
        $this->assertSame($original->error_type, $row->error_type);
    }

    private function seedClient(string $suffix): Client
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'rr-ws-'.$suffix.'-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        return Client::create([
            'name' => 'rr-client-'.$suffix,
            'workspace_id' => $workspace->id,
            'api_key_hash' => random_bytes(32),
            'api_key_prefix' => 'gw_live_'.$suffix,
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_secret'),
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
        ]);
    }

    private function seedRequest(string $requestId, int $clientId, string $status = 'completed'): void
    {
        DB::table('requests')->insert([
            'request_id' => $requestId,
            'client_id' => $clientId,
            'endpoint' => 'messages',
            'mode' => 'sync',
            'model_alias' => 'claude-sonnet',
            'model_snapshot' => 'claude-sonnet-4-6',
            'status' => $status,
            'http_status' => 200,
            'created_at' => now(),
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
