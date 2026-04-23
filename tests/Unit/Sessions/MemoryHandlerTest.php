<?php

declare(strict_types=1);

namespace Tests\Unit\Sessions;

use App\Components\Sessions\DTO\SessionCreateInput;
use App\Components\Sessions\MemoryHandler;
use App\Components\Sessions\SessionStore;
use App\Models\Session;
use App\Models\SessionMemoryFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MemoryHandlerTest extends TestCase
{
    use RefreshDatabase;

    private MemoryHandler $handler;

    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = app(MemoryHandler::class);
        $this->session = $this->createSession();
    }

    #[Test]
    public function view_empty_memories_returns_empty_directory_message(): void
    {
        $result = $this->handler->execute($this->session, $this->toolUse('view', ['path' => '/memories']));

        $this->assertSame('Directory /memories is empty.', $result->text);
        $this->assertFalse($result->isError);
    }

    #[Test]
    public function create_and_view_roundtrip(): void
    {
        $this->handler->execute($this->session, $this->toolUse('create', [
            'path' => '/memories/test.md',
            'file_text' => "line one\nline two",
        ]));

        $result = $this->handler->execute($this->session, $this->toolUse('view', ['path' => '/memories/test.md']));

        $this->assertStringContainsString('1: line one', $result->text);
        $this->assertStringContainsString('2: line two', $result->text);
    }

    #[Test]
    public function create_overwrites_existing_file(): void
    {
        $this->handler->execute($this->session, $this->toolUse('create', [
            'path' => '/memories/file.md',
            'file_text' => 'first',
        ]));

        $this->handler->execute($this->session, $this->toolUse('create', [
            'path' => '/memories/file.md',
            'file_text' => 'second',
        ]));

        $result = $this->handler->execute($this->session, $this->toolUse('view', ['path' => '/memories/file.md']));

        $this->assertStringContainsString('second', $result->text);
        $this->assertStringNotContainsString('first', $result->text);
    }

    #[Test]
    public function str_replace_success(): void
    {
        $this->handler->execute($this->session, $this->toolUse('create', [
            'path' => '/memories/greet.md',
            'file_text' => 'hello world',
        ]));

        $this->handler->execute($this->session, $this->toolUse('str_replace', [
            'path' => '/memories/greet.md',
            'old_str' => 'world',
            'new_str' => 'earth',
        ]));

        $result = $this->handler->execute($this->session, $this->toolUse('view', ['path' => '/memories/greet.md']));

        $this->assertStringContainsString('hello earth', $result->text);
    }

    #[Test]
    public function str_replace_rejects_nonunique_old_str(): void
    {
        $this->handler->execute($this->session, $this->toolUse('create', [
            'path' => '/memories/dup.md',
            'file_text' => "aa\naa",
        ]));

        $result = $this->handler->execute($this->session, $this->toolUse('str_replace', [
            'path' => '/memories/dup.md',
            'old_str' => 'aa',
            'new_str' => 'bb',
        ]));

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('exactly once', $result->text);
    }

    #[Test]
    public function insert_at_start(): void
    {
        $this->handler->execute($this->session, $this->toolUse('create', [
            'path' => '/memories/lines.md',
            'file_text' => "a\nb\nc",
        ]));

        $this->handler->execute($this->session, $this->toolUse('insert', [
            'path' => '/memories/lines.md',
            'insert_line' => 0,
            'insert_text' => 'zeroth',
        ]));

        $result = $this->handler->execute($this->session, $this->toolUse('view', ['path' => '/memories/lines.md']));

        $this->assertStringStartsWith('1: zeroth', $result->text);
    }

    #[Test]
    public function insert_at_end(): void
    {
        $this->handler->execute($this->session, $this->toolUse('create', [
            'path' => '/memories/lines.md',
            'file_text' => "a\nb",
        ]));

        $this->handler->execute($this->session, $this->toolUse('insert', [
            'path' => '/memories/lines.md',
            'insert_line' => 2,
            'insert_text' => 'last',
        ]));

        $result = $this->handler->execute($this->session, $this->toolUse('view', ['path' => '/memories/lines.md']));

        $this->assertStringContainsString('3: last', $result->text);
    }

    #[Test]
    public function insert_out_of_range_fails(): void
    {
        $this->handler->execute($this->session, $this->toolUse('create', [
            'path' => '/memories/lines.md',
            'file_text' => "a\nb",
        ]));

        $result = $this->handler->execute($this->session, $this->toolUse('insert', [
            'path' => '/memories/lines.md',
            'insert_line' => -1,
            'insert_text' => 'bad',
        ]));

        $this->assertTrue($result->isError);

        $result = $this->handler->execute($this->session, $this->toolUse('insert', [
            'path' => '/memories/lines.md',
            'insert_line' => 3,
            'insert_text' => 'bad',
        ]));

        $this->assertTrue($result->isError);
    }

    #[Test]
    public function delete_file(): void
    {
        $this->handler->execute($this->session, $this->toolUse('create', [
            'path' => '/memories/todelete.md',
            'file_text' => 'bye',
        ]));

        $result = $this->handler->execute($this->session, $this->toolUse('delete', ['path' => '/memories/todelete.md']));

        $this->assertStringContainsString('File deleted', $result->text);
    }

    #[Test]
    public function delete_directory_recursive(): void
    {
        $this->handler->execute($this->session, $this->toolUse('create', [
            'path' => '/memories/notes/a.md',
            'file_text' => 'a',
        ]));
        $this->handler->execute($this->session, $this->toolUse('create', [
            'path' => '/memories/notes/b.md',
            'file_text' => 'b',
        ]));
        $this->handler->execute($this->session, $this->toolUse('create', [
            'path' => '/memories/notes/sub/c.md',
            'file_text' => 'c',
        ]));

        $result = $this->handler->execute($this->session, $this->toolUse('delete', ['path' => '/memories/notes']));

        $this->assertFalse($result->isError);

        $remaining = SessionMemoryFile::where('session_id', $this->session->id)
            ->where('path', 'LIKE', '/memories/notes%')
            ->count();

        $this->assertSame(0, $remaining);
    }

    #[Test]
    public function rename_success(): void
    {
        $this->handler->execute($this->session, $this->toolUse('create', [
            'path' => '/memories/old.md',
            'file_text' => 'content',
        ]));

        $result = $this->handler->execute($this->session, $this->toolUse('rename', [
            'old_path' => '/memories/old.md',
            'new_path' => '/memories/new.md',
        ]));

        $this->assertFalse($result->isError);

        $this->assertDatabaseMissing('session_memory_files', [
            'session_id' => $this->session->id,
            'path' => '/memories/old.md',
        ]);
        $this->assertDatabaseHas('session_memory_files', [
            'session_id' => $this->session->id,
            'path' => '/memories/new.md',
        ]);
    }

    #[Test]
    public function rename_to_existing_target_fails(): void
    {
        $this->handler->execute($this->session, $this->toolUse('create', [
            'path' => '/memories/a.md',
            'file_text' => 'a',
        ]));
        $this->handler->execute($this->session, $this->toolUse('create', [
            'path' => '/memories/b.md',
            'file_text' => 'b',
        ]));

        $result = $this->handler->execute($this->session, $this->toolUse('rename', [
            'old_path' => '/memories/a.md',
            'new_path' => '/memories/b.md',
        ]));

        $this->assertTrue($result->isError);
    }

    #[Test]
    public function rename_missing_source_fails(): void
    {
        $result = $this->handler->execute($this->session, $this->toolUse('rename', [
            'old_path' => '/memories/nonexistent.md',
            'new_path' => '/memories/target.md',
        ]));

        $this->assertTrue($result->isError);
    }

    #[Test]
    public function two_sessions_are_isolated(): void
    {
        $session2 = $this->createSession();

        $this->handler->execute($this->session, $this->toolUse('create', [
            'path' => '/memories/shared.md',
            'file_text' => 'session1 content',
        ]));

        $this->handler->execute($session2, $this->toolUse('create', [
            'path' => '/memories/shared.md',
            'file_text' => 'session2 content',
        ]));

        $result1 = $this->handler->execute($this->session, $this->toolUse('view', ['path' => '/memories/shared.md']));
        $result2 = $this->handler->execute($session2, $this->toolUse('view', ['path' => '/memories/shared.md']));

        $this->assertStringContainsString('session1 content', $result1->text);
        $this->assertStringNotContainsString('session2', $result1->text);

        $this->assertStringContainsString('session2 content', $result2->text);
        $this->assertStringNotContainsString('session1', $result2->text);
    }

    private function toolUse(string $command, array $extraInput = []): array
    {
        return [
            'id' => 'tu_1',
            'input' => array_merge(['command' => $command], $extraInput),
        ];
    }

    private function createSession(): Session
    {
        $workspaceId = (int) DB::table('claude_workspaces')->insertGetId([
            'name' => 'ws-'.uniqid(),
            'api_key_encrypted' => Crypt::encryptString('test-key'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clientId = (int) DB::table('clients')->insertGetId([
            'name' => 'client-'.uniqid(),
            'workspace_id' => $workspaceId,
            'api_key_hash' => random_bytes(32),
            'api_key_prefix' => substr(uniqid(), 0, 12),
            'signing_secret_current_encrypted' => Crypt::encryptString('secret'),
            'allowed_features' => json_encode(['messages']),
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $store = app(SessionStore::class);
        $input = new SessionCreateInput(
            clientId: $clientId,
            workspaceId: $workspaceId,
            modelAlias: 'claude-sonnet-4-6',
            system: null,
            tools: [],
            cacheStrategy: 'none',
            contextManagement: [],
            autoResume: false,
            expiresAt: null,
        );

        $metadata = $store->create($input);

        return $store->findByPublicId($metadata->publicId);
    }
}
