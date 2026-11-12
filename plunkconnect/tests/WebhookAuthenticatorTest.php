<?php

declare(strict_types=1);

namespace PlunkConnect\Tests;

use PHPUnit\Framework\TestCase;
use PlunkConnect\Security\WebhookAuthenticator;

class WebhookAuthenticatorTest extends TestCase
{
    private const SECRET = 'test_webhook_secret_0123456789abcdef';
    private const BODY = '{"contact":{"email":"a@b.lt","subscribed":true}}';

    private function auth(int $now = 1700000000): WebhookAuthenticator
    {
        return new WebhookAuthenticator(self::SECRET, static function () use ($now): int {
            return $now;
        });
    }

    public function testBearerAccepted(): void
    {
        $this->assertTrue($this->auth()->isValid(self::BODY, [
            'authorization' => 'Bearer ' . self::SECRET,
        ]));
    }

    public function testBearerCaseInsensitiveScheme(): void
    {
        $this->assertTrue($this->auth()->isValid(self::BODY, [
            'authorization' => 'bearer ' . self::SECRET,
        ]));
    }

    public function testWrongBearerRejected(): void
    {
        $this->assertFalse($this->auth()->isValid(self::BODY, [
            'authorization' => 'Bearer wrong_secret',
        ]));
    }

    public function testMissingAuthRejected(): void
    {
        $this->assertFalse($this->auth()->isValid(self::BODY, []));
    }

    public function testEmptySecretRejectsEverything(): void
    {
        $auth = new WebhookAuthenticator('');
        $this->assertFalse($auth->isValid(self::BODY, [
            'authorization' => 'Bearer ',
        ]));
    }

    public function testHmacAccepted(): void
    {
        $now = 1700000000;
        $signature = hash_hmac('sha256', $now . '.' . self::BODY, self::SECRET);

        $this->assertTrue($this->auth($now)->isValid(self::BODY, [
            'x-plunk-timestamp' => (string) $now,
            'x-plunk-signature' => $signature,
        ]));
    }

    public function testHmacExpiredRejected(): void
    {
        $now = 1700000000;
        $old = $now - WebhookAuthenticator::TIMESTAMP_TOLERANCE - 60;
        $signature = hash_hmac('sha256', $old . '.' . self::BODY, self::SECRET);

        $this->assertFalse($this->auth($now)->isValid(self::BODY, [
            'x-plunk-timestamp' => (string) $old,
            'x-plunk-signature' => $signature,
        ]));
    }

    public function testHmacTamperedBodyRejected(): void
    {
        $now = 1700000000;
        $signature = hash_hmac('sha256', $now . '.' . self::BODY, self::SECRET);

        $this->assertFalse($this->auth($now)->isValid('{"tampered":1}', [
            'x-plunk-timestamp' => (string) $now,
            'x-plunk-signature' => $signature,
        ]));
    }
}
