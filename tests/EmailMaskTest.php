<?php

namespace PlunkConnect\Tests;

use PHPUnit\Framework\TestCase;
use PlunkConnect\Util\EmailMask;

class EmailMaskTest extends TestCase
{
    public function testMask(): void
    {
        $this->assertSame('j***@gmail.com', EmailMask::mask('jonas@gmail.com'));
        $this->assertSame('a***@x.lt', EmailMask::mask('a@x.lt'));
        $this->assertSame('(none)', EmailMask::mask('not-an-email'));
        $this->assertSame('(none)', EmailMask::mask(null));
    }

    public function testScrubReplacesEmbeddedEmails(): void
    {
        $msg = 'HTTP 422 for jonas@gmail.com and info@tvparkas.lt failed';
        $scrubbed = EmailMask::scrub($msg);
        $this->assertStringNotContainsString('jonas@gmail.com', $scrubbed);
        $this->assertStringNotContainsString('info@tvparkas.lt', $scrubbed);
        $this->assertStringContainsString('j***@gmail.com', $scrubbed);
    }
}
