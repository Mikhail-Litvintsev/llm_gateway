<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Batch;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Components\Claude\Batch\BatchCanceler;
use App\Components\Claude\Enums\BatchStatus;
use App\Components\Routing\WorkspaceResolver;
use App\Models\BatchRecord;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class BatchCancelerOrphanTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cancel_throws_runtime_exception_when_client_record_is_missing(): void
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'wk',
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        $generator = new KeyGenerator;
        $rawKey = $generator->generateRawKey();
        $hasher = $this->app->make(KeyHasher::class);

        $client = Client::create([
            'name' => 'temp',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($rawKey),
            'api_key_prefix' => $generator->derivePrefix($rawKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('s'),
            'allowed_features' => [],
            'is_dev_mode' => false,
        ]);

        $batch = BatchRecord::create([
            'batch_id' => 'batch_orphan_test',
            'client_id' => $client->id,
            'anthropic_batch_id' => 'msgbatch_remote',
            'status' => BatchStatus::InProgress,
            'request_count' => 1,
        ]);

        Schema::disableForeignKeyConstraints();
        DB::table('clients')->where('id', $client->id)->delete();
        Schema::enableForeignKeyConstraints();

        $canceler = new BatchCanceler($this->app->make(WorkspaceResolver::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Client not found for batch/');

        $canceler->cancel($batch->fresh());
    }
}
