<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Billing;

use App\Components\Billing\Billing;
use App\Components\Billing\Enums\SpendGateDecision;
use App\Components\Billing\UsageTracker;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BillingTest extends TestCase
{
    use RefreshDatabase;

    private Billing $billing;
    private UsageTracker $usageTracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usageTracker = Mockery::mock(UsageTracker::class);
        $this->billing = new Billing($this->usageTracker);
    }

    #[Test]
    public function pre_check_unlimited_when_no_cap(): void
    {
        $client = $this->makeClientModel([
            'monthly_spend_cap_usd' => null,
            'current_month_spend_usd' => '50.0000',
        ]);

        $result = $this->billing->preCheck($client);

        $this->assertSame(SpendGateDecision::AllowedUnlimited, $result->decision);
        $this->assertTrue($result->isAllowed());
        $this->assertNull($result->capUsd);
    }

    #[Test]
    public function pre_check_within_cap(): void
    {
        $client = $this->makeClientModel([
            'monthly_spend_cap_usd' => '100.00',
            'current_month_spend_usd' => '50.0000',
        ]);

        $result = $this->billing->preCheck($client);

        $this->assertSame(SpendGateDecision::AllowedWithinCap, $result->decision);
        $this->assertTrue($result->isAllowed());
        $this->assertSame(100.0, $result->capUsd);
    }

    #[Test]
    public function pre_check_soft_cap_exceeded(): void
    {
        $client = $this->makeClientModel([
            'monthly_spend_cap_usd' => '100.00',
            'current_month_spend_usd' => '100.0000',
        ]);

        $result = $this->billing->preCheck($client);

        $this->assertSame(SpendGateDecision::SoftCapExceeded, $result->decision);
        $this->assertFalse($result->isAllowed());
    }

    #[Test]
    public function pre_check_hard_cap_exceeded_via_redis(): void
    {
        $client = $this->makeClientModel([
            'monthly_spend_cap_usd' => '100.00',
            'current_month_spend_usd' => '80.0000',
            'allowed_features' => ['hard_cap_enforcement' => true],
        ]);

        $this->usageTracker
            ->shouldReceive('currentSpend')
            ->once()
            ->with($client)
            ->andReturn(100.0);

        $result = $this->billing->preCheck($client);

        $this->assertSame(SpendGateDecision::HardCapExceeded, $result->decision);
        $this->assertFalse($result->isAllowed());
        $this->assertSame(100.0, $result->currentSpendUsd);
    }

    #[Test]
    public function pre_check_hard_cap_within_limit_falls_through_to_soft_check(): void
    {
        $client = $this->makeClientModel([
            'monthly_spend_cap_usd' => '100.00',
            'current_month_spend_usd' => '50.0000',
            'allowed_features' => ['hard_cap_enforcement' => true],
        ]);

        $this->usageTracker
            ->shouldReceive('currentSpend')
            ->once()
            ->with($client)
            ->andReturn(50.0);

        $result = $this->billing->preCheck($client);

        $this->assertSame(SpendGateDecision::AllowedWithinCap, $result->decision);
    }

    #[Test]
    public function record_spend_increments_atomically(): void
    {
        $client = $this->createDbClient([
            'monthly_spend_cap_usd' => '200.00',
            'current_month_spend_usd' => '10.0000',
        ]);

        $this->usageTracker->shouldNotReceive('commit');

        $result = $this->billing->recordSpend($client, 5.50);

        $this->assertSame(15.5, $result->newTotalUsd);
        $this->assertEqualsWithDelta(184.5, $result->remainingUsd, 0.0001);
        $this->assertFalse($result->capJustExceeded);

        $dbValue = (float) DB::table('clients')->where('id', $client->id)->value('current_month_spend_usd');
        $this->assertEqualsWithDelta(15.5, $dbValue, 0.0001);
    }

    #[Test]
    public function record_spend_detects_cap_just_exceeded(): void
    {
        $client = $this->createDbClient([
            'monthly_spend_cap_usd' => '100.00',
            'current_month_spend_usd' => '95.0000',
        ]);

        $this->usageTracker->shouldNotReceive('commit');

        $result = $this->billing->recordSpend($client, 10.0);

        $this->assertTrue($result->capJustExceeded);
    }

    #[Test]
    public function record_spend_with_hard_cap_syncs_redis(): void
    {
        $client = $this->createDbClient([
            'monthly_spend_cap_usd' => '200.00',
            'current_month_spend_usd' => '10.0000',
            'allowed_features' => ['hard_cap_enforcement' => true],
        ]);

        $this->usageTracker
            ->shouldReceive('commit')
            ->once()
            ->with($client, 5.0);

        $this->billing->recordSpend($client, 5.0);
    }

    #[Test]
    public function record_spend_remaining_null_when_no_cap(): void
    {
        $client = $this->createDbClient([
            'monthly_spend_cap_usd' => null,
            'current_month_spend_usd' => '10.0000',
        ]);

        $this->usageTracker->shouldNotReceive('commit');

        $result = $this->billing->recordSpend($client, 5.0);

        $this->assertNull($result->remainingUsd);
        $this->assertFalse($result->capJustExceeded);
    }

    #[Test]
    public function no_pessimistic_locks_during_record_spend(): void
    {
        $client = $this->createDbClient([
            'monthly_spend_cap_usd' => '200.00',
            'current_month_spend_usd' => '10.0000',
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->usageTracker->shouldNotReceive('commit');
        $this->billing->recordSpend($client, 5.0);

        foreach ($queries as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('FOR UPDATE', $sql);
            $this->assertStringNotContainsStringIgnoringCase('LOCK IN SHARE MODE', $sql);
        }
    }

    private function makeClientModel(array $overrides): Client
    {
        $client = new Client();
        $client->id = 1;
        $client->forceFill(array_merge([
            'name' => 'test-client',
            'allowed_features' => [],
            'monthly_spend_cap_usd' => null,
            'current_month_spend_usd' => '0.0000',
        ], $overrides));

        return $client;
    }

    private function createDbClient(array $overrides = []): Client
    {
        $workspaceId = DB::table('claude_workspaces')->insertGetId([
            'name' => 'ws-' . uniqid(),
            'api_key_encrypted' => Crypt::encryptString('test-key'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $defaults = [
            'name' => 'billing-test-client',
            'workspace_id' => $workspaceId,
            'api_key_hash' => random_bytes(32),
            'api_key_prefix' => 'llmgw_test_',
            'signing_secret_current_encrypted' => Crypt::encryptString('secret'),
            'allowed_features' => json_encode([]),
            'rate_limit_rpm' => 60,
            'monthly_spend_cap_usd' => null,
            'current_month_spend_usd' => '0.0000',
            'is_dev_mode' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $merged = array_merge($defaults, $overrides);

        if (is_array($merged['allowed_features'])) {
            $merged['allowed_features'] = json_encode($merged['allowed_features']);
        }

        $id = DB::table('clients')->insertGetId($merged);

        return Client::findOrFail($id);
    }
}
