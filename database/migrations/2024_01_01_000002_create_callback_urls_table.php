<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('callback_urls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->rawIndex('api_client_id, url(255)', 'idx_client_url');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('callback_urls');
    }
};
