<?php

namespace PlunkConnect\Tests;

use PHPUnit\Framework\TestCase;
use PlunkConnect\Command\ReconcileSubscribersCommand;

class ReconcileTargetTest extends TestCase
{
    public function testPsFollowsPlunk(): void
    {
        $this->assertTrue(ReconcileSubscribersCommand::targetPsState(true, true));
        $this->assertFalse(ReconcileSubscribersCommand::targetPsState(false, true));
    }

    public function testNoChangeWhenEveryPsSourceMatches(): void
    {
        $this->assertNull(ReconcileSubscribersCommand::targetPsState(true, false));
        $this->assertNull(ReconcileSubscribersCommand::targetPsState(false, false));
    }
}
