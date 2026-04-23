<?php

declare(strict_types=1);

namespace App\Components\Logging;

final class PayloadMasker
{
    private const string REDACTED = '[REDACTED]';

    private const string SENSITIVE_PATTERN = '/oauth|token|secret|api_key|authorization/i';

    public static function mask(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return $payload;
        }

        return json_encode(self::redactRecursive($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function redactRecursive(array $data): array
    {
        foreach ($data as $key => &$value) {
            if (is_string($key) && preg_match(self::SENSITIVE_PATTERN, $key)) {
                $value = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $value = self::redactRecursive($value);
            }
        }

        return $data;
    }
}
