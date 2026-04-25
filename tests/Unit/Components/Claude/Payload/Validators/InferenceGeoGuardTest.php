<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Payload\Validators;

use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use App\Components\Claude\Payload\Validators\InferenceGeoGuard;
use App\Models\Client;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class InferenceGeoGuardTest extends TestCase
{
    private InferenceGeoGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new InferenceGeoGuard;
    }

    private function client(?string $geo, array $features = []): Client
    {
        $client = new Client;
        $client->forceFill([
            'id' => 1,
            'name' => 't',
            'api_key_hash' => 'h',
            'inference_geo' => $geo,
            'allowed_features' => $features,
        ]);

        return $client;
    }

    #[Test]
    public function passes_when_inference_geo_null(): void
    {
        $this->guard->enforce([], $this->client('eu'));
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function passes_when_matches_client_geo(): void
    {
        $this->guard->enforce(['inference_geo' => 'eu'], $this->client('eu'));
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function throws_when_mismatch_and_no_override(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage("Inference geo 'us' is not allowed for this client");

        $this->guard->enforce(['inference_geo' => 'us'], $this->client('eu'));
    }

    #[Test]
    public function passes_when_mismatch_and_override_allowed(): void
    {
        $this->guard->enforce(
            ['inference_geo' => 'us'],
            $this->client('eu', ['inference_geo_override' => true]),
        );
        $this->expectNotToPerformAssertions();
    }
}
