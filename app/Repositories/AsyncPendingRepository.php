<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\AsyncPending;
use DateTimeInterface;

final class AsyncPendingRepository
{
    public function find(string $requestId): ?AsyncPending
    {
        return AsyncPending::query()->find($requestId);
    }

    public function getStatus(string $requestId): ?string
    {
        $status = AsyncPending::query()->where('request_id', $requestId)->value('status');

        return is_string($status) ? $status : null;
    }

    /**
     * @note Call inside DB::transaction() — this method does not wrap its own.
     */
    public function create(
        string $requestId,
        string $callbackUrl,
        string $payloadJson,
        DateTimeInterface $expiresAt,
    ): void {
        AsyncPending::query()->create([
            'request_id' => $requestId,
            'payload_for_anthropic' => $payloadJson,
            'callback_url' => $callbackUrl,
            'status' => 'queued',
            'callback_attempts' => 0,
            'next_attempt_at' => null,
            'expires_at' => $expiresAt,
        ]);
    }

    public function markProcessing(string $requestId): void
    {
        AsyncPending::query()
            ->where('request_id', $requestId)
            ->update(['status' => 'processing']);
    }

    public function markDelivered(string $requestId, int $callbackAttempts): void
    {
        AsyncPending::query()
            ->where('request_id', $requestId)
            ->update([
                'status' => 'delivered',
                'callback_attempts' => $callbackAttempts,
                'next_attempt_at' => null,
            ]);
    }

    public function markExhausted(string $requestId, int $callbackAttempts): void
    {
        AsyncPending::query()
            ->where('request_id', $requestId)
            ->update([
                'status' => 'exhausted',
                'callback_attempts' => $callbackAttempts,
                'next_attempt_at' => null,
            ]);
    }

    public function scheduleRetry(
        string $requestId,
        int $callbackAttempts,
        DateTimeInterface $nextAttemptAt,
    ): void {
        AsyncPending::query()
            ->where('request_id', $requestId)
            ->update([
                'status' => 'processing',
                'callback_attempts' => $callbackAttempts,
                'next_attempt_at' => $nextAttemptAt,
            ]);
    }
}
