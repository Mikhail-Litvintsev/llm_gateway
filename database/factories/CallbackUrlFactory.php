<?php

namespace Database\Factories;

use App\Models\ApiClient;
use App\Models\CallbackUrl;
use Illuminate\Database\Eloquent\Factories\Factory;

class CallbackUrlFactory extends Factory
{
    protected $model = CallbackUrl::class;

    public function definition(): array
    {
        return [
            'api_client_id' => ApiClient::factory(),
            'url' => 'https://example.com/callback',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
