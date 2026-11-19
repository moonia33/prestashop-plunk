<?php

namespace PlunkConnect\Tests;

use PHPUnit\Framework\TestCase;
use PlunkConnect\Command\ReconcileSubscribersCommand;

class ReconcileTargetTest extends TestCase
{
    public function testPsFollowsPlunk(): void
    {
        $this->assertTrue(ReconcileSubscribersCommand::targetPsState(true, false));
        $this->assertFalse(ReconcileSubscribersCommand::targetPsState(false, true));
    }

    public function testNoChangeWhenTheyAgree(): void
    {
        $this->assertNull(ReconcileSubscribersCommand::targetPsState(true, true));
        $this->assertNull(ReconcileSubscribersCommand::targetPsState(false, false));
    }
}
