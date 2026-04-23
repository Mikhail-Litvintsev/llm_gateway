<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_memory_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('sessions')->restrictOnDelete();
            $table->string('path', 764);
            $table->longText('content');
            $table->timestamps();

            $table->unique(['session_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_memory_files');
    }
};
