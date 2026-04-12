<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id')->unique();
            $table->unsignedBigInteger('client_id');
            $table->string('anthropic_batch_id')->unique()->nullable();
            $table->string('status', 32);
            $table->unsignedInteger('request_count');
            $table->unsignedInteger('succeeded_count')->default(0);
            $table->unsignedInteger('errored_count')->default(0);
            $table->unsignedInteger('cancelled_count')->default(0);
            $table->unsignedInteger('expired_count')->default(0);
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('results_url')->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
