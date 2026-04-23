<?php

declare(strict_types=1);

namespace App\Components\Sessions;

use App\Components\Sessions\Contracts\SessionStoreContract;
use App\Components\Sessions\DTO\SessionCreateInput;
use App\Components\Sessions\DTO\SessionHistoryPage;
use App\Components\Sessions\DTO\SessionMetadata;
use App\Models\Session;
use App\Models\SessionMessage;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final class SessionStore implements SessionStoreContract
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Encrypter $encrypter,
    ) {}

    public function create(SessionCreateInput $input): SessionMetadata
    {
        return $this->db->transaction(function () use ($input): SessionMetadata {
            $publicId = 'sess_' . strtolower(Str::ulid()->toBase32());

            $session = new Session();
            $session->session_id = $publicId;
            $session->client_id = $input->clientId;
            $session->workspace_id = $input->workspaceId;
            $session->model_alias = $input->modelAlias;
            $session->system = $input->system;
            $session->tools = $input->tools;
            $session->mcp_servers = $this->encryptMcpTokens($input->mcpServers);
            $session->cache_strategy = $input->cacheStrategy ?? 'none';
            $session->context_management = $input->contextManagement;
            $session->auto_resume = $input->autoResume;
            $session->expires_at = $input->expiresAt;
            $session->message_count = 0;
            $session->compaction_count = 0;
            $session->total_input_tokens = 0;
            $session->total_output_tokens = 0;
            $session->total_cost_usd = 0;
            $session->save();

            return $this->getMetadata($session);
        });
    }

    public function findByPublicId(string $publicId): ?Session
    {
        return Session::where('session_id', $publicId)->first();
    }

    public function getMetadata(Session $session): SessionMetadata
    {
        return new SessionMetadata(
            publicId: $session->session_id,
            modelAlias: $session->model_alias ?? '',
            messageCount: (int) $session->message_count,
            lastCompactionAt: $session->last_compaction_at
                ? DateTimeImmutable::createFromMutable($session->last_compaction_at->toDateTime())
                : null,
            expiresAt: $session->expires_at
                ? DateTimeImmutable::createFromMutable($session->expires_at->toDateTime())
                : null,
            status: $this->resolveStatus($session),
            compactionCount: (int) $session->compaction_count,
            totalCostUsd: (float) $session->total_cost_usd,
        );
    }

    public function appendUserMessage(Session $session, array $content): SessionMessage
    {
        return $this->db->transaction(function () use ($session, $content): SessionMessage {
            $turnIndex = $this->nextTurnIndex($session);

            $message = new SessionMessage();
            $message->session_id = $session->id;
            $message->turn_index = $turnIndex;
            $message->role = 'user';
            $message->content = $content;
            $message->created_at = now();
            $message->save();

            $session->increment('message_count');

            return $message;
        });
    }

    public function appendAssistantMessage(
        Session $session,
        array $content,
        ?string $stopReason,
        array $usage,
        string $model,
    ): SessionMessage {
        return $this->db->transaction(function () use ($session, $content, $stopReason, $usage, $model): SessionMessage {
            $turnIndex = $this->nextTurnIndex($session);

            $message = new SessionMessage();
            $message->session_id = $session->id;
            $message->turn_index = $turnIndex;
            $message->role = 'assistant';
            $message->content = $content;
            $message->stop_reason = $stopReason;
            $message->usage = $usage;
            $message->model = $model;
            $message->created_at = now();
            $message->save();

            $session->increment('message_count');

            return $message;
        });
    }

    public function loadFullHistory(Session $session): array
    {
        return $session->messages()
            ->orderBy('turn_index')
            ->get(['role', 'content'])
            ->map(fn (SessionMessage $m): array => ['role' => $m->role, 'content' => $m->content])
            ->all();
    }

    public function paginateHistory(Session $session, int $from, int $limit): SessionHistoryPage
    {
        $limit = min($limit, 200);
        $query = $session->messages()->orderBy('turn_index');
        $total = $query->count();

        $messages = $query
            ->offset($from)
            ->limit($limit)
            ->get()
            ->map(fn (SessionMessage $m): array => [
                'turn_index' => $m->turn_index,
                'role' => $m->role,
                'content' => $m->content,
                'stop_reason' => $m->stop_reason,
                'usage' => $m->usage,
                'model' => $m->model,
                'created_at' => $m->created_at?->toIso8601String(),
            ])
            ->all();

        return new SessionHistoryPage(
            from: $from,
            limit: $limit,
            total: $total,
            messages: $messages,
        );
    }

    public function markCompacted(Session $session): void
    {
        $session->last_compaction_at = now();
        $session->compaction_count = (int) $session->compaction_count + 1;
        $session->save();
    }

    public function softDelete(Session $session): void
    {
        $session->delete();
    }

    private function nextTurnIndex(Session $session): int
    {
        $max = $session->messages()->max('turn_index');

        return $max === null ? 0 : ((int) $max + 1);
    }

    public function decryptMcpTokens(?array $mcpServers): ?array
    {
        if ($mcpServers === null) {
            return null;
        }

        foreach ($mcpServers as &$server) {
            if (isset($server['authorization_token'])) {
                $server['authorization_token'] = $this->encrypter->decryptString($server['authorization_token']);
            }
        }

        return $mcpServers;
    }

    private function encryptMcpTokens(?array $mcpServers): ?array
    {
        if ($mcpServers === null) {
            return null;
        }

        foreach ($mcpServers as &$server) {
            if (isset($server['authorization_token'])) {
                $server['authorization_token'] = $this->encrypter->encryptString($server['authorization_token']);
            }
        }

        return $mcpServers;
    }

    private function resolveStatus(Session $session): string
    {
        if ($session->trashed()) {
            return 'deleted';
        }

        if ($session->expires_at !== null && $session->expires_at->isPast()) {
            return 'expired';
        }

        return 'active';
    }
}
