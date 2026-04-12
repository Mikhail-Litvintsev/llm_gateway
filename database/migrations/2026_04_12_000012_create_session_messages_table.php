<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('turn_index');
            $table->enum('role', ['user', 'assistant']);
            $table->longText('content');
            $table->string('stop_reason')->nullable();
            $table->json('usage')->nullable();
            $table->string('model')->nullable();
            $table->char('request_id', 28)->nullable();
            $table->dateTime('created_at');

            $table->foreign('session_id')->references('id')->on('sessions');
            $table->foreign('request_id')->references('request_id')->on('requests');
            $table->index(['session_id', 'turn_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_messages');
    }
};
