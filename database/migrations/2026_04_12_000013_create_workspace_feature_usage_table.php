<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_feature_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('year_month');
            $table->string('feature');
            $table->decimal('value', 12, 4);
            $table->dateTime('updated_at');

            $table->foreign('workspace_id')->references('id')->on('claude_workspaces');
            $table->unique(['workspace_id', 'year_month', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_feature_usage');
    }
};
