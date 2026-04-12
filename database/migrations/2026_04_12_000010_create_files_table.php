<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->string('file_id')->unique();
            $table->unsignedBigInteger('client_id');
            $table->string('anthropic_file_id')->unique();
            $table->string('filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->enum('upload_purpose', ['vision', 'document', 'code_execution_input', 'other']);
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->onDelete('restrict');
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
