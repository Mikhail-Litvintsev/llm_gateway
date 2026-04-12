<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    /** @var string[] */
    private const array LEGACY_TABLES = [
        'session_history',
        'pending_responses',
        'pending_prompts',
        'raw_responses',
        'response_log',
        'request_log',
        'callback_urls',
        'api_clients',
        'jobs',
    ];

    public function up(): void
    {
        if (env('CLAUDE_ALLOW_LEGACY_DROP') !== 'yes-i-confirm-data-loss-2026-05') {
            throw new RuntimeException(
                'Destructive migration. Set CLAUDE_ALLOW_LEGACY_DROP=yes-i-confirm-data-loss-2026-05 to proceed. '
                . 'This will permanently drop api_clients, callback_urls, request_log, response_log, '
                . 'raw_responses, pending_prompts, pending_responses, session_history, jobs.'
            );
        }

        foreach (self::LEGACY_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Legacy tables cannot be restored by rollback. Restore from backup if needed.'
        );
    }
};
