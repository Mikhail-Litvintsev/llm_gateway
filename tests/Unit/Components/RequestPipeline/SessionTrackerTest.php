<?php

namespace Tests\Unit\Components\RequestPipeline;

use App\Components\RequestPipeline\Exceptions\ValidationException;
use App\Components\RequestPipeline\SessionTracker;
use App\Models\SessionHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTrackerTest extends TestCase
{
    use RefreshDatabase;
    private SessionTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = new SessionTracker();
    }

    public function test_validate_step_accepts_first_step(): void
    {
        $this->tracker->validateStep('sess_001', 1, 1);
        $this->assertTrue(true); // No exception
    }

    public function test_validate_step_rejects_duplicate_step(): void
    {
        $client = \App\Models\ApiClient::factory()->create();
        $requestLog = \App\Models\RequestLog::factory()->create(['api_client_id' => $client->id]);

        SessionHistory::create([
            'session_id' => 'sess_001',
            'api_client_id' => $client->id,
            'step_id' => 1,
            'request_log_id' => $requestLog->id,
        ]);

        $this->expectException(ValidationException::class);
        $this->tracker->validateStep('sess_001', 1, $client->id);
    }

    public function test_validate_step_rejects_lower_step(): void
    {
        $client = \App\Models\ApiClient::factory()->create();
        $requestLog = \App\Models\RequestLog::factory()->create(['api_client_id' => $client->id]);

        SessionHistory::create([
            'session_id' => 'sess_001',
            'api_client_id' => $client->id,
            'step_id' => 5,
            'request_log_id' => $requestLog->id,
        ]);

        $this->expectException(ValidationException::class);
        $this->tracker->validateStep('sess_001', 3, $client->id);
    }

    public function test_register_step_creates_history_record(): void
    {
        $client = \App\Models\ApiClient::factory()->create();
        $requestLog = \App\Models\RequestLog::factory()->create(['api_client_id' => $client->id]);

        $this->tracker->registerStep('sess_001', 1, $client->id, $requestLog->id);

        $this->assertDatabaseHas('session_history', [
            'session_id' => 'sess_001',
            'step_id' => 1,
            'api_client_id' => $client->id,
        ]);
    }

    public function test_get_history_returns_ordered_steps(): void
    {
        $client = \App\Models\ApiClient::factory()->create();
        $requestLog1 = \App\Models\RequestLog::factory()->create(['api_client_id' => $client->id]);
        $requestLog2 = \App\Models\RequestLog::factory()->create(['api_client_id' => $client->id]);

        SessionHistory::create(['session_id' => 'sess_001', 'api_client_id' => $client->id, 'step_id' => 2, 'request_log_id' => $requestLog2->id]);
        SessionHistory::create(['session_id' => 'sess_001', 'api_client_id' => $client->id, 'step_id' => 1, 'request_log_id' => $requestLog1->id]);

        $history = $this->tracker->getHistory('sess_001', $client->id);

        $this->assertCount(2, $history);
        $this->assertEquals(1, $history->first()->step_id);
        $this->assertEquals(2, $history->last()->step_id);
    }
}
