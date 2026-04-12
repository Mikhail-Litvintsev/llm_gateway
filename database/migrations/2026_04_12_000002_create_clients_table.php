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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('workspace_id');
            $table->binary('api_key_hash');
            $table->char('api_key_prefix', 12);
            $table->text('signing_secret_current_encrypted');
            $table->dateTime('signing_secret_rotated_at')->nullable();
            $table->string('default_model_alias')->nullable();
            $table->json('allowed_features');
            $table->unsignedInteger('rate_limit_rpm');
            $table->decimal('monthly_spend_cap_usd', 10, 2)->nullable();
            $table->decimal('current_month_spend_usd', 12, 4)->default(0);
            $table->string('inference_geo')->nullable();
            $table->boolean('is_dev_mode')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('claude_workspaces');
            $table->index('api_key_prefix');
        });

        DB::statement('ALTER TABLE clients MODIFY api_key_hash VARBINARY(32) NOT NULL');
        DB::statement('ALTER TABLE clients ADD UNIQUE INDEX clients_api_key_hash_unique (api_key_hash)');
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
