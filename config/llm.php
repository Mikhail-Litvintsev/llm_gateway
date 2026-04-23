<?php

return [
    'version' => '3.0',

    // Максимальный размер тела запроса (байт)
    'max_payload_size' => 50 * 1024 * 1024, // 50 MB

    // TTL временных данных (секунды) — 3 дня
    'pending_ttl' => 3 * 24 * 60 * 60,

    // Провайдеры
    'providers' => [
        'claude' => [
            'endpoint' => env('ANTHROPIC_ENDPOINT', 'https://api.anthropic.com/v1/messages'),
            'api_key' => env('ANTHROPIC_API_KEY'),
            'default_model' => 'claude-sonnet-4-6',
            'default_max_tokens' => 4096,
            'rate_limit' => (int) env('CLAUDE_RATE_LIMIT_RPM', 45),
            'token_limits' => [
                'input_tokens_per_minute'  => (int) env('CLAUDE_ITPM', 30000),
                'output_tokens_per_minute' => (int) env('CLAUDE_OTPM', 8000),
            ],
        ],
        'openai' => [
            'endpoint' => env('OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
            'api_key' => env('OPENAI_API_KEY'),
            'default_model' => 'gpt-4o',
            'rate_limit' => (int) env('OPENAI_RATE_LIMIT_RPM', 60),
        ],
        'deepseek' => [
            'endpoint' => env('DEEPSEEK_ENDPOINT', 'https://api.deepseek.com/chat/completions'),
            'api_key' => env('DEEPSEEK_API_KEY'),
            'default_model' => 'deepseek-chat',
            'rate_limit' => (int) env('DEEPSEEK_RATE_LIMIT_RPM', 60),
        ],
        'gemini' => [
            'endpoint' => env('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models'),
            'api_key' => env('GEMINI_API_KEY'),
            'default_model' => 'gemini-2.0-flash',
            'rate_limit' => (int) env('GEMINI_RATE_LIMIT_RPM', 60),
        ],
        'mistral' => [
            'endpoint' => env('MISTRAL_ENDPOINT', 'https://api.mistral.ai/v1/chat/completions'),
            'api_key' => env('MISTRAL_API_KEY'),
            'default_model' => 'mistral-large-latest',
            'rate_limit' => (int) env('MISTRAL_RATE_LIMIT_RPM', 60),
        ],
    ],

    // Очереди по приоритету
    'queues' => [
        'high' => 'high',
        'normal' => 'default',
        'low' => 'low',
    ],

    // Dev mode stub settings
    'dev_mode' => [
        'latency_ms' => (int) env('DEV_MODE_LATENCY_MS', 150),
        'content' => env('DEV_MODE_CONTENT', 'This is a dev_mode stub response.'),
        'finish_reason' => 'end_turn',
        'input_tokens' => 10,
        'output_tokens' => 5,
        'model' => 'dev-mode-stub',
        'provider' => 'stub',
    ],

    // Callback
    'callback' => [
        'default_timeout' => 300,
        'default_max_attempts' => 3,
        'default_backoff' => 'exponential',
        'default_initial_delay' => 1,
        'max_response_wait' => 10,
    ],
];
