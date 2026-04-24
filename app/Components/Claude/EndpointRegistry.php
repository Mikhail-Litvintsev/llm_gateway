<?php

declare(strict_types=1);

namespace App\Components\Claude;

final readonly class EndpointRegistry
{
    public function messages(): string
    {
        return (string) config('llm.claude.endpoints.messages');
    }

    public function countTokens(): string
    {
        return (string) config('llm.claude.endpoints.count_tokens');
    }

    public function batches(): string
    {
        return (string) config('llm.claude.endpoints.batches');
    }

    public function batch(string $anthropicBatchId): string
    {
        return $this->batches().'/'.$anthropicBatchId;
    }

    public function batchResults(string $anthropicBatchId): string
    {
        return $this->batch($anthropicBatchId).'/results';
    }
}
