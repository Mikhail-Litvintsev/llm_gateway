<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_history', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 256);
            $table->foreignId('api_client_id')->constrained('api_clients');
            $table->unsignedInteger('step_id');
            $table->foreignId('request_log_id')->constrained('request_log')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['session_id', 'api_client_id', 'step_id'], 'idx_session_step');
            $table->index(['session_id', 'api_client_id'], 'idx_session_client');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_history');
    }
};
