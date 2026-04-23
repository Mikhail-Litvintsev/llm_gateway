<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->string('callback_url', 2048)->nullable()->after('results_url');
            $table->boolean('auto_use_1h_cache')->default(true)->after('callback_url');
            $table->unsignedTinyInteger('submit_attempts')->default(0)->after('auto_use_1h_cache');
            $table->string('status', 32)->change();
        });

        Schema::table('batch_items', function (Blueprint $table) {
            $table->unique('request_id');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn(['callback_url', 'auto_use_1h_cache', 'submit_attempts']);
        });

        Schema::table('batch_items', function (Blueprint $table) {
            $table->dropUnique(['request_id']);
        });
    }
};
