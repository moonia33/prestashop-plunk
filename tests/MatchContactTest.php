<?php

namespace PlunkConnect\Tests;

use PHPUnit\Framework\TestCase;
use PlunkConnect\Api\PlunkClient;

class MatchContactTest extends TestCase
{
    public function testExactEmailOnly(): void
    {
        $body = ['contacts' => [
            ['id' => 'a', 'email' => 'jonas@example.lt.lv'],
            ['id' => 'b', 'email' => 'Jonas@Example.lt'],
        ]];
        $this->assertSame('b', PlunkClient::matchContact($body, 'jonas@example.lt')['id']);
        $this->assertNull(PlunkClient::matchContact($body, 'petras@example.lt'));
        $this->assertNull(PlunkClient::matchContact([], 'jonas@example.lt'));
    }
}
