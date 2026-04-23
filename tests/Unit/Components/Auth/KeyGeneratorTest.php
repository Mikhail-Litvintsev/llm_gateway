<?php

namespace Tests\Unit\Components\Auth;

use App\Components\Auth\KeyGenerator;
use PHPUnit\Framework\TestCase;

class KeyGeneratorTest extends TestCase
{
    private KeyGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new KeyGenerator();
    }

    public function test_generates_key_with_default_prefix(): void
    {
        $key = $this->generator->generate();

        $this->assertStringStartsWith('lgw_', $key);
        $this->assertGreaterThan(10, strlen($key));
    }

    public function test_generates_key_with_custom_prefix(): void
    {
        $key = $this->generator->generate('lgs_');

        $this->assertStringStartsWith('lgs_', $key);
    }

    public function test_generates_unique_keys(): void
    {
        $key1 = $this->generator->generate();
        $key2 = $this->generator->generate();

        $this->assertNotEquals($key1, $key2);
    }

    public function test_key_is_url_safe(): void
    {
        $key = $this->generator->generate();
        $withoutPrefix = substr($key, 4);

        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $withoutPrefix);
    }
}
