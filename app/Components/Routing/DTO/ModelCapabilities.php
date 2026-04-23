<?php

declare(strict_types=1);

namespace App\Components\Routing\DTO;

use Illuminate\Support\Carbon;

final readonly class ModelCapabilities
{
    public function __construct(
        public string $modelId,
        public int $maxInputTokens,
        public int $maxTokens,
        public bool $vision,
        public bool $toolUse,
        public bool $extendedThinking,
        public bool $promptCaching,
        public bool $batch,
        public bool $citations,
        public ?Carbon $fetchedAt = null,
    ) {}

    public static function fromApi(array $data): self
    {
        $caps = $data['capabilities'] ?? [];

        return new self(
            modelId: $data['id'] ?? '',
            maxInputTokens: $data['max_input_tokens'] ?? 0,
            maxTokens: $data['max_tokens'] ?? 0,
            vision: $caps['vision'] ?? false,
            toolUse: $caps['tool_use'] ?? false,
            extendedThinking: $caps['extended_thinking'] ?? false,
            promptCaching: $caps['prompt_caching'] ?? false,
            batch: $caps['batch'] ?? false,
            citations: $caps['citations'] ?? false,
            fetchedAt: Carbon::now(),
        );
    }

    public static function fromConfig(string $modelId, array $cfg): self
    {
        return new self(
            modelId: $modelId,
            maxInputTokens: $cfg['context_window'] ?? 0,
            maxTokens: $cfg['max_output'] ?? 0,
            vision: true,
            toolUse: true,
            extendedThinking: $cfg['supports_thinking'] ?? false,
            promptCaching: true,
            batch: true,
            citations: true,
        );
    }

    public function toArray(): array
    {
        return [
            'model_id' => $this->modelId,
            'max_input_tokens' => $this->maxInputTokens,
            'max_tokens' => $this->maxTokens,
            'vision' => $this->vision,
            'tool_use' => $this->toolUse,
            'extended_thinking' => $this->extendedThinking,
            'prompt_caching' => $this->promptCaching,
            'batch' => $this->batch,
            'citations' => $this->citations,
            'fetched_at' => $this->fetchedAt?->toIso8601String(),
        ];
    }

    /** @return array<string, array{config: mixed, live: mixed}> */
    public function diff(self $other): array
    {
        $drift = [];
        $fields = ['maxInputTokens', 'maxTokens', 'vision', 'toolUse', 'extendedThinking', 'promptCaching', 'batch', 'citations'];

        foreach ($fields as $field) {
            if ($this->$field !== $other->$field) {
                $drift[$field] = ['config' => $this->$field, 'live' => $other->$field];
            }
        }

        return $drift;
    }
}
