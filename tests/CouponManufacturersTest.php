<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PlunkConnect\Service\CouponService;

final class CouponManufacturersTest extends TestCase
{
    public function testExcludesBrandsAndKeepsProductsWithoutManufacturer(): void
    {
        $this->assertSame([0, 3, 7], CouponService::manufacturerValues([7, 65, 3, 65], [65]));
    }

    public function testNothingExcluded(): void
    {
        $this->assertSame([0, 1, 2], CouponService::manufacturerValues([2, 1], []));
    }
}
