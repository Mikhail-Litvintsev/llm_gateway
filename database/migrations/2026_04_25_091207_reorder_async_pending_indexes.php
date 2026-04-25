<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('async_pending', function (Blueprint $table): void {
            $table->dropIndex(['status', 'next_attempt_at']);
            $table->index(['next_attempt_at', 'status'], 'async_pending_next_attempt_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('async_pending', function (Blueprint $table): void {
            $table->dropIndex('async_pending_next_attempt_status_idx');
            $table->index(['status', 'next_attempt_at']);
        });
    }
};
