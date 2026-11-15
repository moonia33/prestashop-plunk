<?php

use PHPUnit\Framework\TestCase;
use PlunkConnect\Mapper\OrderMapper;

final class OrderMapperTest extends TestCase
{
    public function testSlug(): void
    {
        $this->assertSame('dugnine-zvejyba', OrderMapper::slug('Dugninė žvejyba'));
        $this->assertSame('karpiavimas', OrderMapper::slug(' Karpiavimas '));
        $this->assertSame('samu-zvejyba', OrderMapper::slug('Šamų žvejyba'));
        $this->assertSame('pludine-zvejyba', OrderMapper::slug('Plūdinė  žvejyba!'));
        $this->assertSame('', OrderMapper::slug('  '));
    }
}
