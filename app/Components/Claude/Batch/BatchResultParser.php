<?php

declare(strict_types=1);

namespace App\Components\Claude\Batch;

use App\Components\Claude\DTO\ResultLine;
use InvalidArgumentException;

final class BatchResultParser
{
    private const array VALID_TYPES = ['succeeded', 'errored', 'canceled', 'expired'];

    public function parseLine(string $jsonl): ?ResultLine
    {
        $trimmed = trim($jsonl);

        if ($trimmed === '') {
            return null;
        }

        $data = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);

        $customId = $data['custom_id'] ?? null;
        $result = $data['result'] ?? [];
        $type = $result['type'] ?? null;

        if ($customId === null || $type === null) {
            throw new InvalidArgumentException("Invalid JSONL line: missing custom_id or result.type");
        }

        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException("Unknown result type: $type");
        }

        return new ResultLine(
            customId: $customId,
            type: $type,
            message: $type === 'succeeded' ? ($result['message'] ?? null) : null,
            error: $type === 'errored' ? ($result['error'] ?? null) : null,
        );
    }
}
