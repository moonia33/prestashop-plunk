<?php

namespace PlunkConnect\Tests;

use PHPUnit\Framework\TestCase;
use PlunkConnect\Service\ContactGuardService;

class ContactGuardTest extends TestCase
{
    private const HOSTS = ['example.com', 'www.example.com'];

    public function testAcceptsMatchingResponse(): void
    {
        $this->assertTrue(ContactGuardService::isValidResponse(
            ['success' => true, 'action' => 'contact_form', 'hostname' => 'WWW.example.com'],
            self::HOSTS
        ));
    }

    public function testRejectsFailedWrongActionOrHost(): void
    {
        $ok = ['success' => true, 'action' => 'contact_form', 'hostname' => 'example.com'];
        $this->assertFalse(ContactGuardService::isValidResponse(['success' => false] + $ok, self::HOSTS));
        $this->assertFalse(ContactGuardService::isValidResponse(['success' => 'true'] + $ok, self::HOSTS));
        $this->assertFalse(ContactGuardService::isValidResponse(['action' => 'newsletter_signup'] + $ok, self::HOSTS));
        $this->assertFalse(ContactGuardService::isValidResponse(['hostname' => 'evil.com'] + $ok, self::HOSTS));
        $this->assertFalse(ContactGuardService::isValidResponse(['hostname' => ''] + $ok, self::HOSTS));
        $this->assertFalse(ContactGuardService::isValidResponse($ok, []));
    }

    public function testEmptyTokenIsRejectedWithoutNetwork(): void
    {
        $this->assertFalse(ContactGuardService::verifyToken('', '1.2.3.4'));
    }

    public function testParsesHostnames(): void
    {
        $this->assertSame(
            ['example.com', 'www.example.com'],
            ContactGuardService::parseHostnames(" https://Example.com/path, www.example.com:443\nexample.com bad_host!")
        );
    }

    public function testWidgetEscapesSiteKey(): void
    {
        $html = ContactGuardService::renderWidget('0x4"><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('data-action="contact_form"', $html);
        $this->assertSame('', ContactGuardService::renderWidget(''));
    }
}
