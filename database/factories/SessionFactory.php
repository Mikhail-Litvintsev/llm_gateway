<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Session;
use Illuminate\Database\Eloquent\Factories\Factory;

final class SessionFactory extends Factory
{
    protected $model = Session::class;

    public function definition(): array
    {
        return [
            'session_id' => 'sess_' . strtolower(fake()->ulid()),
            'client_id' => 1,
            'workspace_id' => 1,
            'model_alias' => 'claude-sonnet',
            'system' => null,
            'tools' => [],
            'cache_strategy' => 'none',
            'context_management' => [],
            'auto_resume' => false,
            'message_count' => 0,
            'compaction_count' => 0,
            'total_input_tokens' => 0,
            'total_output_tokens' => 0,
            'total_cost_usd' => 0,
            'expires_at' => now()->addDays(14),
        ];
    }
}
