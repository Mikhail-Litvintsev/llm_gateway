<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_skills', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('skill_id', 64)->unique();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('anthropic_skill_id', 128)->unique()->nullable();
            $table->string('name', 128);
            $table->string('version', 32)->nullable();
            $table->boolean('is_prebuilt')->default(false);
            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_skills');
    }
};
