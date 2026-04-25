<?php

declare(strict_types=1);

$claudeApiBase = rtrim((string) env('CLAUDE_API_BASE_URL', 'https://api.anthropic.com'), '/');

return [
    'version' => '4.0',

    'max_request_payload_mb' => 32,
    'max_batch_payload_mb' => 256,
    'max_file_size_mb' => 500,
    'async_request_ttl_seconds' => 3 * 24 * 3600,
    'session_default_ttl_days' => 30,
    'raw_log_retention_days' => 14,

    'claude' => [
        'default_api_key' => env('ANTHROPIC_API_KEY'),
        'admin_api_key' => env('CLAUDE_ADMIN_API_KEY'),
        'anthropic_version' => '2023-06-01',

        'base_url' => $claudeApiBase,

        'endpoints' => [
            'messages' => $claudeApiBase.'/v1/messages',
            'count_tokens' => $claudeApiBase.'/v1/messages/count_tokens',
            'batches' => $claudeApiBase.'/v1/messages/batches',
            'files' => $claudeApiBase.'/v1/files',
            'models' => $claudeApiBase.'/v1/models',
            'usage_report' => $claudeApiBase.'/v1/organizations/usage_report/messages',
        ],

        'default_model_alias' => 'claude-sonnet',
        'model_aliases' => [
            'claude-opus' => env('CLAUDE_OPUS_MODEL', 'claude-opus-4-6'),
            'claude-sonnet' => env('CLAUDE_SONNET_MODEL', 'claude-sonnet-4-6'),
            'claude-haiku' => env('CLAUDE_HAIKU_MODEL', 'claude-haiku-4-5'),
        ],

        'model_capabilities' => [
            'claude-opus' => [
                'context_window' => 1_000_000,
                'max_output' => 128_000,
                'max_output_batch' => 300_000,
                'supports_thinking' => true,
                'supports_adaptive_thinking' => true,
                'supports_compaction' => true,
                'supports_prefill' => false,
                'min_cache_tokens' => 4096,
                'supports_fast_mode' => true,
            ],
            'claude-sonnet' => [
                'context_window' => 1_000_000,
                'max_output' => 64_000,
                'max_output_batch' => 300_000,
                'supports_thinking' => true,
                'supports_adaptive_thinking' => true,
                'supports_compaction' => true,
                'supports_prefill' => true,
                'min_cache_tokens' => 2048,
                'supports_fast_mode' => false,
            ],
            'claude-haiku' => [
                'context_window' => 200_000,
                'max_output' => 64_000,
                'max_output_batch' => 64_000,
                'supports_thinking' => true,
                'supports_adaptive_thinking' => false,
                'supports_compaction' => false,
                'supports_prefill' => true,
                'min_cache_tokens' => 4096,
                'supports_fast_mode' => false,
            ],
        ],

        'pricing' => [
            'claude-opus' => [
                'input' => 5.00,
                'output' => 25.00,
                'cache_write_5m' => 6.25,
                'cache_write_1h' => 10.00,
                'cache_read' => 0.50,
                'batch_input' => 2.50,
                'batch_output' => 12.50,
            ],
            'claude-sonnet' => [
                'input' => 3.00,
                'output' => 15.00,
                'cache_write_5m' => 3.75,
                'cache_write_1h' => 6.00,
                'cache_read' => 0.30,
                'batch_input' => 1.50,
                'batch_output' => 7.50,
            ],
            'claude-haiku' => [
                'input' => 1.00,
                'output' => 5.00,
                'cache_write_5m' => 1.25,
                'cache_write_1h' => 2.00,
                'cache_read' => 0.10,
                'batch_input' => 0.50,
                'batch_output' => 2.50,
            ],
            'fast_multiplier' => 6.0,
            'server_tools' => [
                'web_search_per_1k' => 10.00,
                'web_fetch' => 0.0,
                'code_execution_free_hours_per_month' => 1550,
                'code_execution_per_hour' => 0.05,
            ],
        ],

        'inference_geo' => [
            'allowed' => ['us'],
            'multiplier' => 1.10,
        ],

        'beta_headers' => [
            'files_api' => 'files-api-2025-04-14',
            'compaction' => 'compact-2026-01-12',
            'context_management' => 'context-management-2025-06-27',
            'output_300k' => 'output-300k-2026-03-24',
            'mcp_client' => 'mcp-client-2025-11-20',
            'fast_mode' => 'fast-mode-2026-02-01',
            'computer_use' => 'computer-use-2025-01-24',
            'skills' => 'skills-2025-10-02',
        ],

        'rate_limit' => [
            'enforce_locally' => true,
            'safety_margin_pct' => 10,
        ],

        'caching' => [
            'auto_top_level_default' => true,
            'min_prefix_safety_margin_tokens' => 100,
            'default_ttl' => '5m',
            'estimation_chars_per_token' => 3.5,
            'minimum_prefix_tokens' => [
                'opus' => 1024,
                'sonnet' => 1024,
                'haiku' => 2048,
            ],
        ],

        'batch' => [
            'enabled' => true,
            'max_items' => 100_000,
            'max_wait_seconds' => 24 * 3600,
            'auto_use_1h_cache_for_batch' => true,
            'accumulator' => [
                'trigger_count' => 100,
                'trigger_bytes' => 50 * 1024 * 1024,
                'trigger_seconds' => 300,
            ],
        ],

        'thinking' => [
            'default_effort' => 'medium',
        ],

        'skills' => [
            'prebuilt' => ['xlsx', 'docx', 'pptx', 'pdf'],
        ],

        'service_tier' => [
            'default' => 'standard_only',
            'priority_multiplier' => 1.0,
        ],

        'timeouts' => [
            'connect' => 10,
            'request' => 600,
            'streaming' => 1800,
        ],

        'http_retry' => [
            'max_attempts' => (int) env('CLAUDE_HTTP_RETRY_MAX_ATTEMPTS', 3),
            'base_delay_ms' => (int) env('CLAUDE_HTTP_RETRY_BASE_DELAY_MS', 500),
            'retryable_statuses' => [429, 500, 502, 503, 504],
        ],

        'files' => [
            'hard_delete_grace_days' => 14,
            'unused_alert_days' => 90,
        ],

        'count_tokens' => [
            'output_tokens_factor' => 0.5,
        ],
    ],

    'queues' => [
        'high' => 'high',
        'normal' => 'default',
        'low' => 'low',
        'batch' => 'batch',
    ],

    'dev_mode' => [
        'latency_ms' => (int) env('DEV_MODE_LATENCY_MS', 150),
        'content' => env('DEV_MODE_CONTENT', 'This is a dev_mode stub response.'),
        'simulate_cache_hit_rate' => 0.5,
    ],

    'webhook' => [
        'grace_period_seconds' => 86400,
        'default_max_attempts' => 10,
        'backoff' => 'exponential',
        'initial_delay_seconds' => 10,
        'max_delay_seconds' => 3600,
        'request_timeout_seconds' => 30,
        'connect_timeout_seconds' => max(1, (int) env('WEBHOOK_CONNECT_TIMEOUT_SECONDS', 5)),
        'scheduler_batch_size' => max(1, (int) env('WEBHOOK_SCHEDULER_BATCH_SIZE', 500)),
        'timestamp_max_age_seconds' => max(1, (int) env('WEBHOOK_TIMESTAMP_MAX_AGE_SECONDS', 300)),
        'signing_algorithm' => 'sha256',
        'permanent_fail_statuses' => [400, 401, 403, 404, 410, 413, 422],
    ],

    'async' => [
        'pending_ttl_days' => 3,
    ],

    'billing' => [
        'hard_cap' => [
            'redis_key_prefix' => 'llm:billing:spend:',
        ],
    ],

    'auth' => [
        'api_key_pepper' => env('API_KEY_PEPPER'),
    ],

    'security_headers' => [
        'X-Content-Type-Options' => env('SECURITY_X_CONTENT_TYPE_OPTIONS', 'nosniff'),
        'X-Frame-Options' => env('SECURITY_X_FRAME_OPTIONS', 'DENY'),
        'Strict-Transport-Security' => env(
            'SECURITY_HSTS',
            'max-age=31536000; includeSubDomains',
        ),
    ],
];
