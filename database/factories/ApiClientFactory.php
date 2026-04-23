<?php

namespace Database\Factories;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\ApiClient;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApiClientFactory extends Factory
{
    protected $model = ApiClient::class;

    public function definition(): array
    {
        $generator = new KeyGenerator();
        $hasher = new KeyHasher();
        $apiKey = $generator->generate();

        return [
            'name' => $this->faker->company(),
            'api_key_hash' => $hasher->hash($apiKey),
            'api_key_prefix' => $hasher->extractPrefix($apiKey),
            'signing_secret' => $generator->generate('lgs_'),
            'is_active' => true,
            'rate_limit' => 60,
            'allowed_providers' => null,
            'dev_mode' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withProviders(array $providers): static
    {
        return $this->state(['allowed_providers' => $providers]);
    }

    public function withKnownKey(string $apiKey): static
    {
        $hasher = new KeyHasher();

        return $this->state([
            'api_key_hash' => $hasher->hash($apiKey),
            'api_key_prefix' => $hasher->extractPrefix($apiKey),
        ]);
    }
}
