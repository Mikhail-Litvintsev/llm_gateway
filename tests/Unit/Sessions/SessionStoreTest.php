<?php

declare(strict_types=1);

namespace Tests\Unit\Sessions;

use App\Components\Sessions\DTO\SessionCreateInput;
use App\Components\Sessions\SessionStore;
use App\Models\Session;
use App\Models\SessionMemoryFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SessionStoreTest extends TestCase
{
    use RefreshDatabase;

    private SessionStore $store;

    private int $clientId;

    private int $workspaceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = app(SessionStore::class);

        $this->workspaceId = (int) DB::table('claude_workspaces')->insertGetId([
            'name' => 'test-workspace',
            'api_key_encrypted' => Crypt::encryptString('test-key'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->clientId = (int) DB::table('clients')->insertGetId([
            'name' => 'test-client',
            'workspace_id' => $this->workspaceId,
            'api_key_hash' => random_bytes(32),
            'api_key_prefix' => 'test_prefix_',
            'signing_secret_current_encrypted' => Crypt::encryptString('secret'),
            'allowed_features' => json_encode(['messages']),
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function create_persists_session_and_returns_metadata(): void
    {
        $metadata = $this->store->create($this->makeInput());

        $this->assertStringStartsWith('sess_', $metadata->publicId);
        $this->assertDatabaseHas('sessions', ['session_id' => $metadata->publicId]);
    }

    #[Test]
    public function append_user_message_increments_turn_index(): void
    {
        $session = $this->createSession();

        $msg0 = $this->store->appendUserMessage($session, [['type' => 'text', 'text' => 'hello']]);
        $msg1 = $this->store->appendUserMessage($session, [['type' => 'text', 'text' => 'world']]);

        $this->assertSame(0, $msg0->turn_index);
        $this->assertSame(1, $msg1->turn_index);

        $session->refresh();
        $this->assertSame(2, (int) $session->message_count);
    }

    #[Test]
    public function append_assistant_message_preserves_thinking_signatures(): void
    {
        $session = $this->createSession();

        $content = [['type' => 'thinking', 'thinking' => 'test', 'signature' => 'sig_abc123']];

        $this->store->appendAssistantMessage($session, $content, 'end_turn', ['input_tokens' => 10, 'output_tokens' => 5], 'claude-sonnet-4-6');

        $message = DB::table('session_messages')
            ->where('session_id', $session->id)
            ->where('role', 'assistant')
            ->first();

        $storedContent = json_decode($message->content, true);

        $this->assertSame('sig_abc123', $storedContent[0]['signature']);
    }

    #[Test]
    public function load_full_history_returns_role_and_content_only(): void
    {
        $session = $this->createSession();

        $this->store->appendUserMessage($session, [['type' => 'text', 'text' => 'hi']]);
        $this->store->appendAssistantMessage(
            $session,
            [['type' => 'text', 'text' => 'hello']],
            'end_turn',
            ['input_tokens' => 5, 'output_tokens' => 3],
            'claude-sonnet-4-6',
        );

        $history = $this->store->loadFullHistory($session);

        $this->assertCount(2, $history);
        foreach ($history as $entry) {
            $this->assertSame(['role', 'content'], array_keys($entry));
        }
    }

    #[Test]
    public function paginate_history_handles_out_of_range_offset(): void
    {
        $session = $this->createSession();

        $this->store->appendUserMessage($session, [['type' => 'text', 'text' => 'a']]);
        $this->store->appendUserMessage($session, [['type' => 'text', 'text' => 'b']]);
        $this->store->appendUserMessage($session, [['type' => 'text', 'text' => 'c']]);

        $page = $this->store->paginateHistory($session, from: 500, limit: 10);

        $this->assertSame([], $page->messages);
        $this->assertSame(3, $page->total);
    }

    #[Test]
    public function paginate_history_caps_limit_at_200(): void
    {
        $session = $this->createSession();

        $page = $this->store->paginateHistory($session, from: 0, limit: 999);

        $this->assertSame(200, $page->limit);
    }

    #[Test]
    public function mark_compacted_increments_counter(): void
    {
        $session = $this->createSession();

        $this->store->markCompacted($session);
        $this->store->markCompacted($session);

        $session->refresh();

        $this->assertSame(2, (int) $session->compaction_count);
        $this->assertNotNull($session->last_compaction_at);
    }

    #[Test]
    public function soft_delete_leaves_memory_files_untouched(): void
    {
        $session = $this->createSession();

        SessionMemoryFile::create([
            'session_id' => $session->id,
            'path' => '/memories/notes.md',
            'content' => 'some notes',
        ]);

        $this->store->softDelete($session);

        $this->assertSoftDeleted('sessions', ['id' => $session->id]);
        $this->assertDatabaseHas('session_memory_files', [
            'session_id' => $session->id,
            'path' => '/memories/notes.md',
        ]);
    }

    private function makeInput(): SessionCreateInput
    {
        return new SessionCreateInput(
            clientId: $this->clientId,
            workspaceId: $this->workspaceId,
            modelAlias: 'claude-sonnet-4-6',
            system: null,
            tools: [],
            cacheStrategy: 'none',
            contextManagement: [],
            autoResume: false,
            expiresAt: null,
        );
    }

    private function createSession(): Session
    {
        $metadata = $this->store->create($this->makeInput());

        return $this->store->findByPublicId($metadata->publicId);
    }
}
