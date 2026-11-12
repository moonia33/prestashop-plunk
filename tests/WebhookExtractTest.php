<?php

declare(strict_types=1);

namespace PlunkConnect\Tests;

use PHPUnit\Framework\TestCase;
use PlunkConnect\Newsletter\WebhookHandler;

class WebhookExtractTest extends TestCase
{
    public function testDefaultPlunkShape(): void
    {
        $result = WebhookHandler::extractSubscription([
            'contact' => ['email' => 'a@b.lt', 'subscribed' => true, 'data' => []],
            'workflow' => ['id' => 'w1'],
            'event' => ['reason' => ''],
        ]);

        $this->assertSame(['email' => 'a@b.lt', 'subscribed' => true], $result);
    }

    public function testCustomFlatBody(): void
    {
        $result = WebhookHandler::extractSubscription([
            'email' => 'a@b.lt',
            'subscribed' => false,
        ]);

        $this->assertSame(['email' => 'a@b.lt', 'subscribed' => false], $result);
    }

    public function testEventNameFallback(): void
    {
        $this->assertSame(
            ['email' => 'a@b.lt', 'subscribed' => true],
            WebhookHandler::extractSubscription(['email' => 'a@b.lt', 'event' => 'contact.subscribed'])
        );
        $this->assertSame(
            ['email' => 'a@b.lt', 'subscribed' => false],
            WebhookHandler::extractSubscription(['email' => 'a@b.lt', 'event' => 'contact.unsubscribed'])
        );
    }

    public function testMissingEmailRejected(): void
    {
        $this->assertNull(WebhookHandler::extractSubscription(['subscribed' => true]));
    }

    public function testInvalidEmailRejected(): void
    {
        $this->assertNull(WebhookHandler::extractSubscription(['email' => 'not-an-email', 'subscribed' => true]));
    }

    public function testMissingDecisionRejected(): void
    {
        $this->assertNull(WebhookHandler::extractSubscription(['email' => 'a@b.lt']));
    }
}
