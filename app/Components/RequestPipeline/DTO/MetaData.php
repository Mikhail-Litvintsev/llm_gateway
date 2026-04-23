<?php

namespace App\Components\RequestPipeline\DTO;

readonly class MetaData
{
    public function __construct(
        public string $requestId,
        public ?string $sessionId,
        public ?int $stepId,
        public ?string $timestamp,
        public ?string $source,
        public ?string $userId,
        public ?string $priority,
        public array $extraFields,
    ) {}

    public function toArray(): array
    {
        $data = array_filter([
            'request_id' => $this->requestId,
            'session_id' => $this->sessionId,
            'step_id' => $this->stepId,
            'timestamp' => $this->timestamp,
            'source' => $this->source,
            'user_id' => $this->userId,
            'priority' => $this->priority,
        ], fn ($v) => $v !== null);

        return array_merge($data, $this->extraFields);
    }
}
