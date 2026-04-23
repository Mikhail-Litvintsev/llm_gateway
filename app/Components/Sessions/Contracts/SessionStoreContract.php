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

    public function appendUserMessage(Session $session, array $content): SessionMessage;

    public function appendAssistantMessage(
        Session $session,
        array $content,
        ?string $stopReason,
        array $usage,
        string $model,
    ): SessionMessage;

    public function loadFullHistory(Session $session): array;

    public function paginateHistory(Session $session, int $from, int $limit): SessionHistoryPage;

    public function markCompacted(Session $session): void;

    public function softDelete(Session $session): void;

    public function decryptMcpTokens(?array $mcpServers): ?array;
}
