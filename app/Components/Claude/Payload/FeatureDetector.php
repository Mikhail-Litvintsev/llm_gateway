<?php

declare(strict_types=1);

namespace App\Components\Claude\Payload;

final readonly class FeatureDetector
{
    public function __construct(
        private PayloadInspector $inspector,
    ) {}

    /**
     * Extracts the list of feature keys the payload exercises.
     *
     * Possible keys: thinking, web_search, code_execution, computer_use, bash, text_editor,
     * priority_tier, citations, prompt_caching, structured_outputs.
     *
     * @param  array<string, mixed>  $payload
     * @return string[]
     */
    public function detect(array $payload): array
    {
        $features = [];

        if (isset($payload['thinking'])) {
            $features[] = 'thinking';
        }

        if (isset($payload['tools']) && is_array($payload['tools'])) {
            $features = [...$features, ...$this->detectToolFeatures($payload['tools'])];
        }

        if (($payload['service_tier'] ?? null) === 'auto') {
            $features[] = 'priority_tier';
        }

        if (! empty($payload['citations']['enabled'])) {
            $features[] = 'citations';
        }

        if ($this->inspector->hasCacheControl($payload)) {
            $features[] = 'prompt_caching';
        }

        if (isset($payload['output_config'])) {
            $features[] = 'structured_outputs';
        }

        return array_values(array_unique($features));
    }

    /**
     * @param  array<int, mixed>  $tools
     * @return string[]
     */
    private function detectToolFeatures(array $tools): array
    {
        $found = [];

        foreach ($tools as $tool) {
            if (! is_array($tool)) {
                continue;
            }

            $name = $tool['name'] ?? '';
            if (! is_string($name)) {
                continue;
            }

            if (str_starts_with($name, 'web_search')) {
                $found[] = 'web_search';
            }
            if ($name === 'code_execution') {
                $found[] = 'code_execution';
            }
            if (str_starts_with($name, 'computer_')) {
                $found[] = 'computer_use';
            }
            if ($name === 'bash') {
                $found[] = 'bash';
            }
            if ($name === 'text_editor') {
                $found[] = 'text_editor';
            }
        }

        return $found;
    }
}
