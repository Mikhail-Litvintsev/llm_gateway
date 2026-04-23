<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_log', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 256);
            $table->foreignId('api_client_id')->constrained('api_clients');
            $table->string('session_id', 256)->nullable();
            $table->unsignedInteger('step_id')->nullable();
            $table->string('provider_requested', 50)->nullable();
            $table->string('model_requested', 100)->nullable();
            $table->string('provider_used', 50)->nullable();
            $table->string('model_used', 100)->nullable();
            $table->boolean('is_fallback')->default(false);
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $table->enum('status', ['accepted', 'processing', 'completed', 'failed', 'timeout'])->default('accepted');
            $table->string('callback_url', 2048);
            $table->json('meta_data');
            $table->boolean('has_tools')->default(false);
            $table->boolean('has_media')->default(false);
            $table->boolean('stream')->default(false);
            $table->string('idempotency_key', 256)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->unique(['request_id', 'api_client_id'], 'idx_request_id_client');
            $table->index('session_id', 'idx_session');
            $table->index('status', 'idx_status');
            $table->index('api_client_id', 'idx_client');
            $table->index(['idempotency_key', 'api_client_id'], 'idx_idempotency');
            $table->index('created_at', 'idx_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_log');
    }
};
