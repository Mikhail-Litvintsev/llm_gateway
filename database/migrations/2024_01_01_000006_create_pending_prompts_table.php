<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_log_id')->unique()->constrained('request_log')->cascadeOnDelete();
            $table->longText('prompt_xml');
            $table->text('tools_xml')->nullable();
            $table->text('parameters_xml')->nullable();
            $table->json('assembled_payload')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();

            $table->index('expires_at', 'idx_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_prompts');
    }
};
