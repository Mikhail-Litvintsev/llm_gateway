<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requests', function (Blueprint $table) {
            $table->char('request_id', 28)->primary();
            $table->unsignedBigInteger('client_id');
            $table->enum('endpoint', ['messages', 'batch_item', 'count_tokens', 'session_message']);
            $table->enum('mode', ['sync', 'sync_stream', 'async_callback', 'batch']);
            $table->string('model_alias');
            $table->string('model_snapshot');
            $table->string('anthropic_request_id')->nullable();
            $table->string('anthropic_organization_id')->nullable();
            $table->string('status', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->string('service_tier_used')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->foreign('client_id')->references('id')->on('clients');
            $table->index(['client_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
