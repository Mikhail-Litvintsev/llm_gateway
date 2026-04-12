<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->string('custom_id');
            $table->longText('payload');
            $table->enum('status', ['pending', 'succeeded', 'errored', 'cancelled', 'expired']);
            $table->longText('result_payload')->nullable();
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->char('request_id', 28)->nullable();

            $table->foreign('batch_id')->references('id')->on('batches')->onDelete('restrict');
            $table->foreign('request_id')->references('request_id')->on('requests')->onDelete('restrict');
            $table->unique(['batch_id', 'custom_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_items');
    }
};
