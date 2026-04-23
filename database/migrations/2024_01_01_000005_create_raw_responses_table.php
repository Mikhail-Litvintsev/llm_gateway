<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_log_id')->constrained('request_log')->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('model', 100);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('response_body');
            $table->json('response_headers')->nullable();
            $table->boolean('is_fallback_attempt')->default(false);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('request_log_id', 'idx_request');
            $table->index('created_at', 'idx_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_responses');
    }
};
