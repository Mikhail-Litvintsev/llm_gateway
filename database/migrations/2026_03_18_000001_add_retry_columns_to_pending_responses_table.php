<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_responses', function (Blueprint $table) {
            if (!Schema::hasColumn('pending_responses', 'retry_backoff')) {
                $table->string('retry_backoff', 20)->default('exponential')->after('max_attempts');
            }
            if (!Schema::hasColumn('pending_responses', 'retry_initial_delay')) {
                $table->unsignedInteger('retry_initial_delay')->default(1)->after('retry_backoff');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pending_responses', function (Blueprint $table) {
            $table->dropColumn(['retry_backoff', 'retry_initial_delay']);
        });
    }
};
