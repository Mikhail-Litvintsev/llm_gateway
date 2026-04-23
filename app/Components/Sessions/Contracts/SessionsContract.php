<?php

declare(strict_types=1);

namespace App\Components\Sessions\Contracts;

use App\Components\Sessions\DTO\SessionCreateInput;
use App\Components\Sessions\DTO\SessionHistoryPage;
use App\Components\Sessions\DTO\SessionMetadata;
use App\Components\Sessions\DTO\SessionSendMessageInput;
use App\Components\Sessions\DTO\SessionSendMessageResult;
use Generator;

interface SessionsContract
{
    public function create(SessionCreateInput $input): SessionMetadata;

    public function getMetadata(string $publicId): SessionMetadata;

    public function paginateHistory(string $publicId, int $from, int $limit): SessionHistoryPage;

    public function sendSync(string $publicId, SessionSendMessageInput $input): SessionSendMessageResult;

    public function sendStream(string $publicId, SessionSendMessageInput $input): Generator;

    public function delete(string $publicId): void;
}
