<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table): void {
            $table->decimal('cache_hit_ratio', 5, 4)->nullable()->after('total_cost_usd');
            $table->decimal('total_savings_from_caching_usd', 12, 4)->nullable()->after('cache_hit_ratio');
            $table->unsignedBigInteger('total_cache_read_tokens')->default(0)->after('total_savings_from_caching_usd');
            $table->unsignedBigInteger('total_input_tokens')->default(0)->after('total_cache_read_tokens');
            $table->unsignedBigInteger('total_output_tokens')->default(0)->after('total_input_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table): void {
            $table->dropColumn([
                'cache_hit_ratio',
                'total_savings_from_caching_usd',
                'total_cache_read_tokens',
                'total_input_tokens',
                'total_output_tokens',
            ]);
        });
    }
};
