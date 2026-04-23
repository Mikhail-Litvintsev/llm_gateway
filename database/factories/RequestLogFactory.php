<?php

namespace Database\Factories;

use App\Components\RequestPipeline\Enums\RequestStatus;
use App\Models\ApiClient;
use App\Models\RequestLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class RequestLogFactory extends Factory
{
    protected $model = RequestLog::class;

    public function definition(): array
    {
        return [
            'request_id' => 'req_' . $this->faker->unique()->lexify('??????'),
            'api_client_id' => ApiClient::factory(),
            'status' => RequestStatus::Accepted,
            'callback_url' => 'https://example.com/callback',
            'meta_data' => ['request_id' => 'req_001'],
            'has_tools' => false,
            'has_media' => false,
            'stream' => false,
            'priority' => 'normal',
        ];
    }

    public function processing(): static
    {
        return $this->state(['status' => RequestStatus::Processing]);
    }

    public function completed(): static
    {
        return $this->state(['status' => RequestStatus::Completed]);
    }

    public function failed(): static
    {
        return $this->state(['status' => RequestStatus::Failed]);
    }
}
