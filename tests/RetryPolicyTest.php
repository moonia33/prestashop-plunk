<?php

declare(strict_types=1);

namespace PlunkConnect\Tests;

use PHPUnit\Framework\TestCase;
use PlunkConnect\Outbox\RetryPolicy;

class RetryPolicyTest extends TestCase
{
    public function testExponentialDelays(): void
    {
        $policy = new RetryPolicy();

        $this->assertSame(60, $policy->delayForAttempt(1));    // 1 min
        $this->assertSame(300, $policy->delayForAttempt(2));   // 5 min
        $this->assertSame(900, $policy->delayForAttempt(3));   // 15 min
        $this->assertSame(3600, $policy->delayForAttempt(4));  // 60 min
        $this->assertSame(21600, $policy->delayForAttempt(5)); // 6 h
        $this->assertSame(21600, $policy->delayForAttempt(8)); // capped at last value
    }

    public function testDeadAfterMaxAttempts(): void
    {
        $policy = new RetryPolicy();

        $this->assertTrue($policy->shouldRetry(7));
        $this->assertFalse($policy->shouldRetry(8));
        $this->assertFalse($policy->shouldRetry(9));
    }
}
