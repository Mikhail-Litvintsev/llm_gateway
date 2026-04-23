<?php

namespace App\Components\ProviderGateway\Enums;

enum ProviderName: string
{
    case Claude = 'claude';
    case OpenAi = 'openai';
    case DeepSeek = 'deepseek';
    case Gemini = 'gemini';
    case Mistral = 'mistral';

    public static function fromModel(string $model): ?self
    {
        return match (true) {
            str_starts_with($model, 'claude') => self::Claude,
            str_starts_with($model, 'gpt') || str_starts_with($model, 'o1') || str_starts_with($model, 'o3') || str_starts_with($model, 'o4') => self::OpenAi,
            str_starts_with($model, 'deepseek') => self::DeepSeek,
            str_starts_with($model, 'gemini') => self::Gemini,
            str_starts_with($model, 'mistral') || str_starts_with($model, 'codestral') => self::Mistral,
            default => null,
        };
    }
}
