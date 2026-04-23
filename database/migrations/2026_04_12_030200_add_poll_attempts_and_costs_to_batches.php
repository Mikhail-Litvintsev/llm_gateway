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
            $table->dateTime('last_polled_at')->nullable()->after('completed_at');
            $table->unsignedInteger('poll_attempts')->default(0)->after('last_polled_at');
            $table->decimal('total_cost_usd', 12, 4)->default(0)->after('submit_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn(['last_polled_at', 'poll_attempts', 'total_cost_usd']);
        });
    }
};
