<?php

declare(strict_types=1);

namespace App\Components\Claude\Batch\Accumulator;

final readonly class AccumulatorBucketKey
{
    private const WINDOW_SECONDS = 300;

    public string $key;
    public int $clientId;
    public string $modelAlias;
    public int $windowStart;

    public function __construct(int $clientId, string $modelAlias, ?int $now = null)
    {
        $now ??= time();
        $this->clientId = $clientId;
        $this->modelAlias = $modelAlias;
        $this->windowStart = (int) (floor($now / self::WINDOW_SECONDS) * self::WINDOW_SECONDS);
        $this->key = "acc:{$this->clientId}:{$this->modelAlias}:{$this->windowStart}";
    }

    public function metaKey(): string
    {
        return $this->key . ':meta';
    }

    public function idsKey(): string
    {
        return $this->key . ':ids';
    }

    public static function pendingSetKey(): string
    {
        return 'acc:pending';
    }

    public static function lockKey(string $bucketKey): string
    {
        return "acc:lock:{$bucketKey}";
    }

    public static function parseClientId(string $bucketKey): int
    {
        $parts = explode(':', $bucketKey);

        return (int) $parts[1];
    }
}
