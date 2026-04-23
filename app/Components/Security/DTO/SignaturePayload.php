<?php

namespace App\Components\Security\DTO;

readonly class SignaturePayload
{
    public function __construct(
        public string $signature,
        public int $timestamp,
        public string $nonce,
        public string $requestId,
    ) {}

    public function toHeaders(): array
    {
        return [
            'X-LLM-Signature' => $this->signature,
            'X-LLM-Timestamp' => (string) $this->timestamp,
            'X-LLM-Nonce' => $this->nonce,
            'X-LLM-Request-Id' => $this->requestId,
        ];
    }
}
