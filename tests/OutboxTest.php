<?php

namespace PlunkConnect\Tests;

use PHPUnit\Framework\TestCase;
use PlunkConnect\Mapper\LocaleMapper;
use PlunkConnect\Outbox\Outbox;
use PlunkConnect\Outbox\OutboxProcessor;

class OutboxTest extends TestCase
{
    public function testUuidIsV4Format(): void
    {
        $uuid = Outbox::uuid();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
        $this->assertNotSame(Outbox::uuid(), Outbox::uuid());
    }

    public function testRetryDelaysGrowAndAreCapped(): void
    {
        $delays = OutboxProcessor::RETRY_DELAYS;
        $this->assertSame([60, 300, 900, 3600, 21600], $delays);
        for ($i = 1; $i < count($delays); ++$i) {
            $this->assertGreaterThan($delays[$i - 1], $delays[$i]);
        }
        $this->assertSame(8, OutboxProcessor::MAX_ATTEMPTS);
    }

    public function testLocaleAllowListRejectsRu(): void
    {
        $this->assertTrue(LocaleMapper::isAllowed('lt'));
        $this->assertTrue(LocaleMapper::isAllowed('LV'));
        $this->assertFalse(LocaleMapper::isAllowed('ru'));
        $this->assertFalse(LocaleMapper::isAllowed('de'));
    }
}
