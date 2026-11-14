<?php

use PHPUnit\Framework\TestCase;
use PlunkConnect\Catalog\CatalogIds;

final class CatalogIdTest extends TestCase
{
    public function testUniqueReferenceIsTheId(): void
    {
        $this->assertSame('FX.CSL123', CatalogIds::choose(' FX.CSL123 ', 1, 42));
        $this->assertSame('16-20-12/30-6', CatalogIds::choose('16-20-12/30-6', 1, 42, 7));
        $this->assertSame('4409', CatalogIds::choose('4409', 1, 10));
    }

    public function testMissingOrDuplicateReferenceFallsBack(): void
    {
        $this->assertSame('PS-42', CatalogIds::choose('', 0, 42));
        $this->assertSame('PS-42', CatalogIds::choose('   ', 0, 42));
        $this->assertSame('PS-42-7', CatalogIds::choose('DY110', 9, 42, 7));
        $this->assertSame('PS-42-7', CatalogIds::choose('DY110', 0, 42, 7));
        $this->assertSame('PS-42', CatalogIds::choose(str_repeat('x', 65), 1, 42));
    }

    public function testReferenceShapedLikeFallbackIsNotUsed(): void
    {
        $this->assertSame('PS-1', CatalogIds::choose('PS-99', 1, 1));
        $this->assertSame('PS-1-2', CatalogIds::choose('ps-99-3', 1, 1, 2));
    }

    public function testFallbackFormat(): void
    {
        $this->assertSame('PS-42', CatalogIds::fallback(42));
        $this->assertSame('PS-42', CatalogIds::fallback(42, 0));
        $this->assertSame('PS-42-7', CatalogIds::fallback(42, 7));
    }

    public function testParse(): void
    {
        $this->assertSame([42, 0], CatalogIds::parse('PS-42'));
        $this->assertSame([42, 7], CatalogIds::parse('PS-42-7'));
        $this->assertSame([42, 7], CatalogIds::parse('42-7')); // pre-1.3 ids
        $this->assertSame([42, 0], CatalogIds::parse('42'));
        $this->assertNull(CatalogIds::parse('FX.CSL123'));
        $this->assertNull(CatalogIds::parse('DY110'));
    }

    public function testRefKeyIgnoresCaseAndSpaces(): void
    {
        $this->assertSame(CatalogIds::refKey('DY110 '), CatalogIds::refKey('dy110'));
    }
}
