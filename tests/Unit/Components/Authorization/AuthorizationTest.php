<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Authorization;

use App\Components\Authorization\Authorization;
use App\Components\Authorization\Enums\AuthorizationDenialReason;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthorizationTest extends TestCase
{
    private Authorization $authorization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorization = new Authorization;
    }

    #[Test]
    public function model_allowed_when_whitelist_absent(): void
    {
        $client = $this->makeClient(['allowed_features' => []]);

        $result = $this->authorization->authorize($client, 'claude-sonnet-4-6', []);

        $this->assertTrue($result->allowed);
    }

    #[Test]
    public function model_allowed_when_whitelist_has_empty_models(): void
    {
        $client = $this->makeClient(['allowed_features' => ['models' => []]]);

        $result = $this->authorization->authorize($client, 'claude-sonnet-4-6', []);

        $this->assertTrue($result->allowed);
    }

    #[Test]
    public function model_denied_when_whitelist_excludes_it(): void
    {
        $client = $this->makeClient([
            'allowed_features' => ['models' => ['gpt-4o']],
        ]);

        $result = $this->authorization->authorize($client, 'claude-sonnet-4-6', []);

        $this->assertFalse($result->allowed);
        $this->assertSame(AuthorizationDenialReason::ModelNotAllowed, $result->reason);
    }

    #[Test]
    public function model_allowed_when_whitelist_includes_it(): void
    {
        $client = $this->makeClient([
            'allowed_features' => ['models' => ['claude-sonnet-4-6', 'gpt-4o']],
        ]);

        $result = $this->authorization->authorize($client, 'claude-sonnet-4-6', []);

        $this->assertTrue($result->allowed);
    }

    #[Test]
    public function feature_denied_when_client_entry_missing(): void
    {
        $client = $this->makeClient(['allowed_features' => []]);

        $result = $this->authorization->authorize($client, 'claude-sonnet-4-6', ['thinking']);

        $this->assertFalse($result->allowed);
        $this->assertSame(AuthorizationDenialReason::FeatureNotAllowed, $result->reason);
        $this->assertSame('thinking', $result->deniedFeature);
    }

    #[Test]
    public function feature_allowed_when_client_entry_true(): void
    {
        $client = $this->makeClient([
            'allowed_features' => ['thinking' => true, 'web_search' => true],
        ]);

        $result = $this->authorization->authorize($client, 'claude-sonnet-4-6', ['thinking', 'web_search']);

        $this->assertTrue($result->allowed);
    }

    #[Test]
    public function feature_denied_when_client_entry_false(): void
    {
        $client = $this->makeClient([
            'allowed_features' => ['thinking' => false],
        ]);

        $result = $this->authorization->authorize($client, 'claude-sonnet-4-6', ['thinking']);

        $this->assertFalse($result->allowed);
        $this->assertSame(AuthorizationDenialReason::FeatureNotAllowed, $result->reason);
    }

    #[Test]
    public function prompt_caching_default_allow(): void
    {
        $client = $this->makeClient(['allowed_features' => []]);

        $result = $this->authorization->authorize($client, 'claude-sonnet-4-6', ['prompt_caching']);

        $this->assertTrue($result->allowed);
    }

    #[Test]
    public function citations_default_allow(): void
    {
        $client = $this->makeClient(['allowed_features' => []]);

        $result = $this->authorization->authorize($client, 'claude-sonnet-4-6', ['citations']);

        $this->assertTrue($result->allowed);
    }

    #[Test]
    public function monthly_spend_cap_exceeded(): void
    {
        $client = $this->makeClient([
            'allowed_features' => [],
            'monthly_spend_cap_usd' => '100.00',
            'current_month_spend_usd' => '100.00',
        ]);

        $result = $this->authorization->authorize($client, 'claude-sonnet-4-6', []);

        $this->assertFalse($result->allowed);
        $this->assertSame(AuthorizationDenialReason::MonthlySpendCapExceeded, $result->reason);
    }

    #[Test]
    public function spend_cap_allows_when_under_limit(): void
    {
        $client = $this->makeClient([
            'allowed_features' => [],
            'monthly_spend_cap_usd' => '100.00',
            'current_month_spend_usd' => '99.99',
        ]);

        $result = $this->authorization->authorize($client, 'claude-sonnet-4-6', []);

        $this->assertTrue($result->allowed);
    }

    #[Test]
    public function spend_cap_allows_when_no_cap_set(): void
    {
        $client = $this->makeClient([
            'allowed_features' => [],
            'monthly_spend_cap_usd' => null,
            'current_month_spend_usd' => '9999.00',
        ]);

        $result = $this->authorization->authorize($client, 'claude-sonnet-4-6', []);

        $this->assertTrue($result->allowed);
    }

    #[Test]
    public function no_sql_issued(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $client = $this->makeClient([
            'allowed_features' => ['thinking' => true],
            'monthly_spend_cap_usd' => '500.00',
            'current_month_spend_usd' => '100.00',
        ]);

        $this->authorization->authorize($client, 'claude-sonnet-4-6', ['thinking']);

        $this->assertEmpty($queries, 'Authorization should not issue any SQL queries');
    }

    private function makeClient(array $attributes): Client
    {
        $client = new Client;
        $client->id = 1;
        $client->forceFill(array_merge([
            'name' => 'test-client',
            'allowed_features' => [],
            'monthly_spend_cap_usd' => null,
            'current_month_spend_usd' => '0.0000',
        ], $attributes));

        return $client;
    }
}
