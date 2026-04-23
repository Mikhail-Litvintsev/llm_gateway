<?php

declare(strict_types=1);

namespace App\Components\Logging\DTO;

use App\Components\Logging\Enums\Endpoint;
use App\Components\Logging\Enums\Mode;
use App\Components\Logging\Enums\RequestStatus;
use DateTimeImmutable;

final readonly class LoggingRecord
{
    public function __construct(
        public string $requestId,
        public int $clientId,
        public Endpoint $endpoint,
        public Mode $mode,
        public string $modelAlias,
        public string $modelSnapshot,
        public ?string $anthropicRequestId,
        public ?string $anthropicOrganizationId,
        public RequestStatus $status,
        public ?int $httpStatus,
        public ?string $errorType,
        public ?string $errorMessage,
        public ?string $serviceTierUsed,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheCreation5mTokens = 0,
        public int $cacheCreation1hTokens = 0,
        public int $cacheReadTokens = 0,
        public int $thinkingTokens = 0,
        public int $serverToolWebSearchCount = 0,
        public int $serverToolWebFetchCount = 0,
        public int $serverToolCodeExecCount = 0,
        public int $serverToolToolSearchCount = 0,
        public string $costUsd = '0.00000000',
        public ?array $costBreakdown = null,
        public ?array $iterationsJson = null,
        public ?array $rateLimitHeaders = null,
        public string $requestPayload = '',
        public ?string $responsePayload = null,
        public DateTimeImmutable $retentionUntil = new DateTimeImmutable('+3 days'),
    ) {}
}
