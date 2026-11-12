<?php

declare(strict_types=1);

namespace PlunkConnect\Tests;

use PHPUnit\Framework\TestCase;
use PlunkConnect\Api\PlunkResponse;

class PlunkResponseTest extends TestCase
{
    public function testSuccess(): void
    {
        $this->assertTrue((new PlunkResponse(200, null, ''))->isSuccess());
        $this->assertTrue((new PlunkResponse(204, null, ''))->isSuccess());
        $this->assertFalse((new PlunkResponse(500, null, ''))->isSuccess());
    }

    public function testIdempotencyConflictIsNotPermanent(): void
    {
        $conflict = new PlunkResponse(409, ['code' => 'IDEMPOTENCY_KEY_REUSED'], '');
        $this->assertTrue($conflict->isIdempotencyConflict());
        $this->assertFalse($conflict->isPermanentError());

        // Unknown 409 (not an idempotency reuse) is a permanent error.
        $other = new PlunkResponse(409, ['error' => 'something else'], '');
        $this->assertFalse($other->isIdempotencyConflict());
        $this->assertTrue($other->isPermanentError());
    }

    public function testRetryableCodes(): void
    {
        foreach ([0, 408, 429, 500, 502, 503] as $code) {
            $this->assertFalse((new PlunkResponse($code, null, ''))->isPermanentError(), "code $code should be retryable");
        }
    }

    public function testPermanentCodes(): void
    {
        foreach ([400, 401, 403, 404, 422] as $code) {
            $this->assertTrue((new PlunkResponse($code, null, ''))->isPermanentError(), "code $code should be permanent");
        }
    }

    public function testRateLimited(): void
    {
        $r = new PlunkResponse(429, null, '', 30);
        $this->assertTrue($r->isRateLimited());
        $this->assertSame(30, $r->retryAfter);
    }
}
