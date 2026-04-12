<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claude_workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->default('default');
            $table->text('api_key_encrypted');
            $table->string('anthropic_workspace_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $apiKey = (string) env('ANTHROPIC_API_KEY', '');

        if ($apiKey === '') {
            if (app()->environment('production')) {
                throw new RuntimeException(
                    'ANTHROPIC_API_KEY is empty in production. Set it in .env before running migrations '
                    .'or default workspace will be unusable.'
                );
            }

            return;
        }

        DB::table('claude_workspaces')->insertOrIgnore([
            'name' => 'default',
            'api_key_encrypted' => Crypt::encryptString($apiKey),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('claude_workspaces');
    }
};
