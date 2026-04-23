<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('response_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_log_id')->constrained('request_log')->cascadeOnDelete();
            $table->enum('status', ['ok', 'error']);
            $table->string('finish_reason', 50)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cache_creation_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->boolean('has_tool_calls')->default(false);
            $table->unsignedSmallInteger('tool_calls_count')->default(0);
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->string('provider_used', 50);
            $table->string('model_used', 100);
            $table->boolean('is_fallback')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->boolean('structured_output_fallback')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index('request_log_id', 'idx_request');
            $table->index('created_at', 'idx_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('response_log');
    }
};
