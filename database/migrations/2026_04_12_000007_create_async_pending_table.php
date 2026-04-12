<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('async_pending', function (Blueprint $table) {
            $table->char('request_id', 28)->primary();
            $table->longText('payload_for_anthropic');
            $table->string('callback_url');
            $table->enum('status', ['queued', 'processing', 'delivered', 'exhausted']);
            $table->unsignedInteger('callback_attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->foreign('request_id')->references('request_id')->on('requests')->onDelete('restrict');
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('async_pending');
    }
};
