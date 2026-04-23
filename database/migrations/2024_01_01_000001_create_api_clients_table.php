<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('api_key_hash')->unique();
            $table->string('api_key_prefix', 8);
            $table->string('signing_secret');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('rate_limit')->default(60);
            $table->json('allowed_providers')->nullable();
            $table->timestamps();

            $table->index('api_key_prefix', 'idx_api_key_prefix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};
