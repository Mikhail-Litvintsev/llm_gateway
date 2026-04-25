<?php

declare(strict_types=1);

namespace App\Components\Sessions\DTO;

final readonly class SessionHistoryPage
{
    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function __construct(
        public int $from,
        public int $limit,
        public int $total,
        public array $messages,
    ) {}
}
