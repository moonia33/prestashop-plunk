<?php

declare(strict_types=1);

namespace PlunkConnect\Tests;

use PHPUnit\Framework\TestCase;
use PlunkConnect\Util\EmailMask;

class EmailMaskTest extends TestCase
{
    public function testMask(): void
    {
        $this->assertSame('j***@gmail.com', EmailMask::mask('jonas@gmail.com'));
        $this->assertSame('a***@b.lt', EmailMask::mask('a@b.lt'));
        $this->assertSame('***', EmailMask::mask('noatsign'));
        $this->assertSame('***', EmailMask::mask('@domain.lt'));
    }
}
