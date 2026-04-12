<?php

declare(strict_types=1);

namespace App\Components\Auth;

/**
 * API keys are generated with 256 bits of entropy — brute force is infeasible, so a KDF provides
 * no security benefit. Argon2id would add tens of milliseconds per middleware call (hot path)
 * without reducing any real-world attack surface. A global pepper closes the rainbow-table vector
 * that Argon2id would mitigate for low-entropy secrets. The unique index on api_key_hash gives
 * O(1) lookup; per-row salt would force an O(N) table scan because middleware would not know
 * which row's salt to apply first — that would defeat the unique index entirely.
 */
final class KeyHasher
{
    public function __construct(
        private readonly string $pepper,
    ) {
        if ($pepper === '') {
            throw new \RuntimeException('API_KEY_PEPPER must not be empty');
        }
    }

    public function hash(string $rawKey): string
    {
        return hash('sha256', $rawKey . $this->pepper, binary: true);
    }

    public function verify(string $rawKey, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($rawKey));
    }
}
