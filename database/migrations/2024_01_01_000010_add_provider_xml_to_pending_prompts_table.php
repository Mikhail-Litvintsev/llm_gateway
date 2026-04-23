<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_prompts', function (Blueprint $table) {
            $table->text('provider_xml')->nullable()->after('parameters_xml');
        });
    }

    public function down(): void
    {
        Schema::table('pending_prompts', function (Blueprint $table) {
            $table->dropColumn('provider_xml');
        });
    }
};
