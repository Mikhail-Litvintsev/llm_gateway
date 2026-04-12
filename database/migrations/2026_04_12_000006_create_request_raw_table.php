<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_raw', function (Blueprint $table) {
            $table->char('request_id', 28)->primary();
            $table->longText('request_payload');
            $table->longText('response_payload')->nullable();
            $table->dateTime('retention_until');

            $table->foreign('request_id')->references('request_id')->on('requests')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_raw');
    }
};
