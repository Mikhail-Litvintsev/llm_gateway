<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique();
            $table->unsignedBigInteger('client_id');
            $table->string('model_alias')->nullable();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->longText('system')->nullable();
            $table->json('tools')->nullable();
            $table->enum('cache_strategy', ['none', 'auto_top_level', 'manual']);
            $table->json('context_management')->nullable();
            $table->unsignedBigInteger('total_input_tokens')->default(0);
            $table->unsignedBigInteger('total_output_tokens')->default(0);
            $table->decimal('total_cost_usd', 12, 4)->default(0);
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedInteger('compaction_count')->default(0);
            $table->boolean('auto_resume')->default(false);
            $table->dateTime('last_compaction_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('client_id')->references('id')->on('clients');
            $table->foreign('workspace_id')->references('id')->on('claude_workspaces');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
