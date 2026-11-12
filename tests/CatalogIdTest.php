<?php

use PHPUnit\Framework\TestCase;
use PlunkConnect\Mapper\CatalogMapper;

final class CatalogIdTest extends TestCase
{
    public function testSimpleProductId(): void
    {
        $this->assertSame('42', CatalogMapper::plunkId(42));
        $this->assertSame('42', CatalogMapper::plunkId(42, 0));
    }

    public function testCombinationId(): void
    {
        $this->assertSame('42-7', CatalogMapper::plunkId(42, 7));
        $this->assertSame('1-123', CatalogMapper::plunkId(1, 123));
    }

    public function testSplitRoundTrip(): void
    {
        $mapper = new CatalogMapper();
        $this->assertSame([42, 0], $mapper->splitPlunkId('42'));
        $this->assertSame([42, 7], $mapper->splitPlunkId('42-7'));
        $this->assertSame([5, 0], $mapper->splitPlunkId('5-'));
    }
}
