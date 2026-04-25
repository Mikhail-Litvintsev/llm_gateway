<?php

declare(strict_types=1);

namespace App\Components\Sessions\Contracts;

use App\Components\Sessions\DTO\SessionCreateInput;
use App\Components\Sessions\DTO\SessionHistoryPage;
use App\Components\Sessions\DTO\SessionMetadata;
use App\Models\Session;
use App\Models\SessionMessage;

interface SessionStoreContract
{
    public function create(SessionCreateInput $input): SessionMetadata;

    public function findByPublicId(string $publicId): ?Session;

    public function getMetadata(Session $session): SessionMetadata;

    /**
     * @param  array<int, mixed>  $content
     */
    public function appendUserMessage(Session $session, array $content): SessionMessage;

    /**
     * @param  array<int, array<string, mixed>>  $content
     * @param  array<string, mixed>  $usage
     */
    public function appendAssistantMessage(
        Session $session,
        array $content,
        ?string $stopReason,
        array $usage,
        string $model,
    ): SessionMessage;

    /**
     * @return list<array{role: string, content: array<int, array<string, mixed>>}>
     */
    public function loadFullHistory(Session $session): array;

    public function paginateHistory(Session $session, int $from, int $limit): SessionHistoryPage;

    public function markCompacted(Session $session): void;

    public function softDelete(Session $session): void;

    /**
     * @param  array<int, array<string, mixed>>|null  $mcpServers
     * @return array<int, array<string, mixed>>|null
     */
    public function decryptMcpTokens(?array $mcpServers): ?array;
}
