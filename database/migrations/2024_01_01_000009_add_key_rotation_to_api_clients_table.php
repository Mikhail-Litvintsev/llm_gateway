<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->string('previous_key_hash')->nullable()->after('api_key_hash');
            $table->timestamp('previous_key_expires_at')->nullable()->after('previous_key_hash');

            $table->index('previous_key_hash', 'idx_previous_key_hash');
        });
    }

    public function down(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->dropIndex('idx_previous_key_hash');
            $table->dropColumn(['previous_key_hash', 'previous_key_expires_at']);
        });
    }
};
