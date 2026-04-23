<?php

namespace Tests\Unit\Components\CallbackDelivery;

use App\Components\CallbackDelivery\RetryCalculator;
use PHPUnit\Framework\TestCase;

class RetryCalculatorTest extends TestCase
{
    private RetryCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new RetryCalculator();
    }

    public function test_exponential_backoff_first_attempt(): void
    {
        $this->assertEquals(1, $this->calculator->calculateDelay(1, 'exponential', 1));
    }

    public function test_exponential_backoff_second_attempt(): void
    {
        $this->assertEquals(2, $this->calculator->calculateDelay(2, 'exponential', 1));
    }

    public function test_exponential_backoff_third_attempt(): void
    {
        $this->assertEquals(4, $this->calculator->calculateDelay(3, 'exponential', 1));
    }

    public function test_exponential_backoff_with_custom_initial_delay(): void
    {
        $this->assertEquals(5, $this->calculator->calculateDelay(1, 'exponential', 5));
        $this->assertEquals(10, $this->calculator->calculateDelay(2, 'exponential', 5));
        $this->assertEquals(20, $this->calculator->calculateDelay(3, 'exponential', 5));
    }

    public function test_fixed_backoff(): void
    {
        $this->assertEquals(3, $this->calculator->calculateDelay(1, 'fixed', 3));
        $this->assertEquals(3, $this->calculator->calculateDelay(2, 'fixed', 3));
        $this->assertEquals(3, $this->calculator->calculateDelay(3, 'fixed', 3));
    }

    public function test_unknown_backoff_defaults_to_initial_delay(): void
    {
        $this->assertEquals(2, $this->calculator->calculateDelay(1, 'unknown', 2));
        $this->assertEquals(2, $this->calculator->calculateDelay(5, 'unknown', 2));
    }
}
