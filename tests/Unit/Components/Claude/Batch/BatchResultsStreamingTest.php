<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Batch;

use App\Components\Claude\Claude;
use App\Components\RateLimiting\Claude\ClaudeRateLimitTracker;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BatchResultsStreamingTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->seedClient();

        $this->mock(ClaudeRateLimitTracker::class, function ($mock) {
            $mock->shouldReceive('canProceed')->andReturn(null);
            $mock->shouldReceive('recordFromHeaders')->andReturn(null);
        });
    }

    #[Test]
    public function get_batch_results_yields_one_result_per_ndjson_line(): void
    {
        $lines = [
            json_encode(['custom_id' => 'a', 'result' => ['type' => 'succeeded', 'message' => ['id' => 'm1', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]]]),
            json_encode(['custom_id' => 'b', 'result' => ['type' => 'succeeded', 'message' => ['id' => 'm2', 'usage' => ['input_tokens' => 20, 'output_tokens' => 10]]]]),
            json_encode(['custom_id' => 'c', 'result' => ['type' => 'errored', 'error' => ['type' => 'foo', 'message' => 'bad']]]),
        ];

        Http::fake([
            'https://api.anthropic.com/v1/messages/batches/btch_test/results' => Http::response(
                implode("\n", $lines)."\n",
                200,
            ),
        ]);

        $results = iterator_to_array(app(Claude::class)->getBatchResults('btch_test', $this->client));

        $this->assertCount(3, $results);
        $this->assertSame('a', $results[0]->customId);
        $this->assertSame('succeeded', $results[0]->type);
        $this->assertSame('errored', $results[2]->type);
    }

    #[Test]
    public function get_batch_results_handles_trailing_line_without_newline(): void
    {
        $lines = [
            json_encode(['custom_id' => 'a', 'result' => ['type' => 'succeeded', 'message' => ['id' => 'm1', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]]]),
            json_encode(['custom_id' => 'b', 'result' => ['type' => 'succeeded', 'message' => ['id' => 'm2', 'usage' => ['input_tokens' => 20, 'output_tokens' => 10]]]]),
        ];

        Http::fake([
            'https://api.anthropic.com/v1/messages/batches/btch_test/results' => Http::response(
                implode("\n", $lines),
                200,
            ),
        ]);

        $results = iterator_to_array(app(Claude::class)->getBatchResults('btch_test', $this->client));

        $this->assertCount(2, $results);
        $this->assertSame('b', $results[1]->customId);
    }

    #[Test]
    public function get_batch_results_makes_single_http_call(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages/batches/btch_x/results' => Http::response('', 200),
        ]);

        iterator_to_array(app(Claude::class)->getBatchResults('btch_x', $this->client));

        $this->assertCount(1, Http::recorded());
    }

    private function seedClient(): Client
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'streaming-ws-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        return Client::create([
            'name' => 'streaming-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => random_bytes(32),
            'api_key_prefix' => 'gw_live_xxx',
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_secret'),
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
        ]);
    }
}
