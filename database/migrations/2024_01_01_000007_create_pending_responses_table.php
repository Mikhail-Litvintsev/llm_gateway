<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_log_id')->unique()->constrained('request_log')->cascadeOnDelete();
            $table->json('response_payload');
            $table->string('callback_url', 2048);
            $table->string('callback_method', 4)->default('POST');
            $table->json('callback_headers')->nullable();
            $table->enum('delivery_status', ['pending', 'delivering', 'delivered', 'failed'])->default('pending');
            $table->unsignedSmallInteger('delivery_attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->string('retry_backoff', 20)->default('exponential');
            $table->unsignedInteger('retry_initial_delay')->default(1);
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('delivery_status', 'idx_delivery_status');
            $table->index('next_retry_at', 'idx_next_retry');
            $table->index('expires_at', 'idx_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_responses');
    }
};
