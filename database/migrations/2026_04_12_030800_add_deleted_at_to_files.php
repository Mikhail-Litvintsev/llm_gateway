<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('files', 'deleted_at')) {
            return;
        }

        Schema::table('files', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('is_deleted');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('files', 'deleted_at')) {
            return;
        }

        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });
    }
};
