<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_usage', function (Blueprint $table) {
            $table->char('request_id', 28)->primary();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_creation_5m_tokens')->default(0);
            $table->unsignedBigInteger('cache_creation_1h_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('thinking_tokens')->default(0);
            $table->unsignedInteger('server_tool_web_search_count')->default(0);
            $table->unsignedInteger('server_tool_web_fetch_count')->default(0);
            $table->unsignedInteger('server_tool_code_exec_count')->default(0);
            $table->unsignedInteger('server_tool_tool_search_count')->default(0);
            $table->decimal('cost_usd', 12, 8);
            $table->json('cost_breakdown');
            $table->json('iterations_json')->nullable();
            $table->json('rate_limit_headers')->nullable();

            $table->foreign('request_id')->references('request_id')->on('requests')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_usage');
    }
};
