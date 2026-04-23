<?php

namespace Tests\Unit\Components\Auth;

use App\Components\Auth\KeyHasher;
use PHPUnit\Framework\TestCase;

class KeyHasherTest extends TestCase
{
    private KeyHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new KeyHasher();
    }

    public function test_hash_produces_sha256(): void
    {
        $hash = $this->hasher->hash('lgw_test_key');

        $this->assertEquals(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function test_hash_is_deterministic(): void
    {
        $hash1 = $this->hasher->hash('lgw_test_key');
        $hash2 = $this->hasher->hash('lgw_test_key');

        $this->assertEquals($hash1, $hash2);
    }

    public function test_different_keys_produce_different_hashes(): void
    {
        $hash1 = $this->hasher->hash('lgw_key_one');
        $hash2 = $this->hasher->hash('lgw_key_two');

        $this->assertNotEquals($hash1, $hash2);
    }

    public function test_extract_prefix(): void
    {
        $prefix = $this->hasher->extractPrefix('lgw_abcdefghijk');

        $this->assertEquals('lgw_abcd', $prefix);
    }
}
