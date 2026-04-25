<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Payload\Validators;

use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use App\Components\Claude\Payload\Validators\ServiceTierGuard;
use App\Models\Client;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ServiceTierGuardTest extends TestCase
{
    private ServiceTierGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new ServiceTierGuard;
    }

    private function client(array $features = []): Client
    {
        $client = new Client;
        $client->forceFill(['id' => 1, 'name' => 't', 'api_key_hash' => 'h', 'allowed_features' => $features]);

        return $client;
    }

    #[Test]
    public function passes_when_service_tier_not_priority(): void
    {
        $this->guard->enforce(['service_tier' => 'standard'], $this->client());
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function passes_when_service_tier_priority_and_client_has_feature(): void
    {
        $this->guard->enforce(
            ['service_tier' => 'priority'],
            $this->client(['priority_tier' => true]),
        );
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function throws_permission_error_when_priority_and_client_lacks_feature(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('Client is not authorized to use priority service tier');

        $this->guard->enforce(['service_tier' => 'priority'], $this->client());
    }
}
