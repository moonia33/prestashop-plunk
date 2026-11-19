<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PlunkConnect\Command\NewProductsCommand;
use PlunkConnect\Command\WinbackCommand;

final class WinbackNewProductsTest extends TestCase
{
    public function testWinbackBounds(): void
    {
        $now = strtotime('2026-11-19 10:00:00');
        $this->assertSame(['2026-08-14 10:00:00', '2026-08-21 10:00:00'], WinbackCommand::bounds($now, 90, 7));
    }

    public function testPickPrefersDistinctCategoriesThenFills(): void
    {
        $c = [
            ['id_product' => 10, 'id_category_default' => 1],
            ['id_product' => 9, 'id_category_default' => 1],
            ['id_product' => 8, 'id_category_default' => 2],
            ['id_product' => 7, 'id_category_default' => 2],
        ];
        $this->assertSame([10, 8, 9], NewProductsCommand::pick($c, 3));
        $this->assertSame([10], NewProductsCommand::pick($c, 1));
    }
}
