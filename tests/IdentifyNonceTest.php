<?php

use PHPUnit\Framework\TestCase;
use PlunkConnect\Service\IdentityService;

final class IdentifyNonceTest extends TestCase
{
    private const RANDOM = '0123456789abcdef0123456789abcdef';

    public function testFreshNonceIsAccepted(): void
    {
        $now = 1794820000;
        $this->assertTrue(IdentityService::nonceFresh(($now - 3600) . '.' . self::RANDOM, $now));
        $this->assertTrue(IdentityService::nonceFresh(($now - 172800) . '.' . self::RANDOM, $now));
    }

    public function testExpiredFutureOrMalformedNonceIsRejected(): void
    {
        $now = 1794820000;
        $this->assertFalse(IdentityService::nonceFresh(($now - 172801) . '.' . self::RANDOM, $now));
        $this->assertFalse(IdentityService::nonceFresh(($now + 60) . '.' . self::RANDOM, $now));
        $this->assertFalse(IdentityService::nonceFresh(self::RANDOM, $now));
        $this->assertFalse(IdentityService::nonceFresh('', $now));
        $this->assertFalse(IdentityService::nonceFresh($now . '.' . self::RANDOM . "\n", $now));
    }
}
