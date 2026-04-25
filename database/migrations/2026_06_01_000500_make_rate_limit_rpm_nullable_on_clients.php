<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedInteger('rate_limit_rpm')->nullable()->change();
        });
    }

    public function down(): void
    {
        $default = (int) config('llm.rate_limit.default_per_minute', 600);
        DB::table('clients')->whereNull('rate_limit_rpm')->update(['rate_limit_rpm' => $default]);

        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedInteger('rate_limit_rpm')->nullable(false)->change();
        });
    }
};
