<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SessionMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

final class SessionMessageFactory extends Factory
{
    protected $model = SessionMessage::class;

    public function definition(): array
    {
        return [
            'session_id' => SessionFactory::new(),
            'turn_index' => 0,
            'role' => 'user',
            'content' => [['type' => 'text', 'text' => 'Hello']],
            'stop_reason' => null,
            'usage' => null,
            'model' => null,
            'created_at' => now(),
        ];
    }
}
