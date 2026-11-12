<?php

declare(strict_types=1);

namespace PlunkConnect\Tests;

use PHPUnit\Framework\TestCase;
use PlunkConnect\Newsletter\NewsletterPayload;

class NewsletterPayloadTest extends TestCase
{
    public function testSignupRequestedIsUnsubscribed(): void
    {
        $payload = NewsletterPayload::signupRequested('a@b.lt', ['locale' => 'lt']);

        $this->assertSame('newsletter.signup_requested', $payload['event']);
        $this->assertSame('a@b.lt', $payload['email']);
        $this->assertFalse($payload['subscribed']);
        $this->assertSame('lt', $payload['data']['locale']);
    }

    public function testSubscribedPayload(): void
    {
        $payload = NewsletterPayload::subscribed('a@b.lt', []);
        $this->assertSame('newsletter.subscribed', $payload['event']);
        $this->assertTrue($payload['subscribed']);
    }

    public function testUnsubscribedPayload(): void
    {
        $payload = NewsletterPayload::unsubscribed('a@b.lt', []);
        $this->assertSame('newsletter.unsubscribed', $payload['event']);
        $this->assertFalse($payload['subscribed']);
    }

    public function testConsentDataShape(): void
    {
        $data = NewsletterPayload::consentData('lt', 'website_signup', 'bz', '1.2.3.4', 'https://shop.lt/', [
            'firstName' => 'Jonas',
            'customerId' => 42,
            'empty' => '',
            'null' => null,
        ]);

        $this->assertSame('prestashop', $data['source']);
        $this->assertSame('website_signup', $data['consentSource']);
        $this->assertSame('bz', $data['shopCode']);
        $this->assertSame('1.2.3.4', $data['consentIp']);
        $this->assertSame('https://shop.lt/', $data['consentReferer']);
        $this->assertSame('Jonas', $data['firstName']);
        $this->assertSame(42, $data['customerId']);
        $this->assertArrayNotHasKey('empty', $data);
        $this->assertArrayNotHasKey('null', $data);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $data['consentRequestedAt']);
    }

    public function testConsentDataOmitsEmptyIpAndReferer(): void
    {
        $data = NewsletterPayload::consentData('en', 'src', 'bz');
        $this->assertArrayNotHasKey('consentIp', $data);
        $this->assertArrayNotHasKey('consentReferer', $data);
    }
}
