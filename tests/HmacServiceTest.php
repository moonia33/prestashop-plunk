<?php

namespace PlunkConnect\Tests;

use PHPUnit\Framework\TestCase;
use PlunkConnect\Security\HmacService;

class HmacServiceTest extends TestCase
{
    private const SECRET = 'test-secret-0123456789abcdef';

    public function testSignVerifyRoundTrip(): void
    {
        $hmac = new HmacService(self::SECRET);
        $sig = $hmac->sign('payload');
        $this->assertTrue($hmac->verify('payload', $sig));
        $this->assertFalse($hmac->verify('tampered', $sig));
        $this->assertFalse($hmac->verify('payload', 'zz' . substr($sig, 2)));
    }

    public function testVerifyWebhookTimestampWindow(): void
    {
        $hmac = new HmacService(self::SECRET);
        $body = '{"event":"contact.unsubscribed","email":"a@b.lt"}';
        $ts = (string) time();
        $sig = $hmac->sign($ts . '.' . $body);

        $this->assertTrue($hmac->verifyWebhook($ts, $body, $sig));
        $this->assertFalse($hmac->verifyWebhook((string) (time() - 301), $body, $sig));
        $this->assertFalse($hmac->verifyWebhook('not-a-number', $body, $sig));
        $this->assertFalse($hmac->verifyWebhook($ts, $body . 'x', $sig));
    }

    public function testEmailTokenRoundTrip(): void
    {
        $hmac = new HmacService(self::SECRET);
        $token = $hmac->encodeEmailToken('User@Example.com');
        $this->assertSame('user@example.com', $hmac->decodeEmailToken($token));
        $this->assertNull($hmac->decodeEmailToken($token . 'ff'));
        $this->assertNull($hmac->decodeEmailToken('garbage'));
    }

    public function testEmailTokenIsUrlSafe(): void
    {
        $hmac = new HmacService(self::SECRET);
        $token = $hmac->encodeEmailToken('u+s.e/r@example.com');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_\-.]+$/', $token);
    }
}
