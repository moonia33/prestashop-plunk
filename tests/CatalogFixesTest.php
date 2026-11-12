<?php

use PHPUnit\Framework\TestCase;
use PlunkConnect\Mapper\CatalogMapper;

/**
 * Regression tests for the production fixes (spec: produkcijos pataisos).
 */
final class CatalogFixesTest extends TestCase
{
    protected function tearDown(): void
    {
        Configuration::$testValues = [];
    }

    private static function row(int $outOfStock): array
    {
        return ['active' => 1, 'visibility' => 'both', 'out_of_stock' => $outOfStock];
    }

    // -- available: out_of_stock 0/1/2 x PS_ORDER_OUT_OF_STOCK 0/1 ----------

    public function testAvailabilityMatrix(): void
    {
        // out_of_stock = 0 -> deny backorders even if shop allows them.
        Configuration::$testValues['PS_ORDER_OUT_OF_STOCK'] = 1;
        $this->assertFalse(CatalogMapper::isAvailable(self::row(0), 0));
        // out_of_stock = 1 -> always allow.
        $this->assertTrue(CatalogMapper::isAvailable(self::row(1), 0));
        // out_of_stock = 2 -> follow shop default (allowed here).
        $this->assertTrue(CatalogMapper::isAvailable(self::row(2), 0));

        Configuration::$testValues['PS_ORDER_OUT_OF_STOCK'] = 0;
        // shop default denies -> out_of_stock = 2 means NOT available.
        $this->assertFalse(CatalogMapper::isAvailable(self::row(2), 0));

        // Stock > 0 always wins.
        $this->assertTrue(CatalogMapper::isAvailable(self::row(0), 3));
    }

    public function testAvailabilityRequiresActiveAndVisible(): void
    {
        Configuration::$testValues['PS_ORDER_OUT_OF_STOCK'] = 1;
        $this->assertFalse(CatalogMapper::isAvailable(['active' => 0, 'visibility' => 'both', 'out_of_stock' => 1], 5));
        $this->assertFalse(CatalogMapper::isAvailable(['active' => 1, 'visibility' => 'none', 'out_of_stock' => 1], 5));
    }

    // -- httpsUrl ------------------------------------------------------------

    public function testSchemelessUrlGetsHttps(): void
    {
        $this->assertSame(
            'https://cdn.example.lt/img/p/1.jpg',
            CatalogMapper::httpsUrl('//cdn.example.lt/img/p/1.jpg')
        );
    }

    public function testHttpUpgradedWhenSslEnabled(): void
    {
        Configuration::$testValues['PS_SSL_ENABLED'] = 1;
        $this->assertSame('https://x.lt/i.jpg', CatalogMapper::httpsUrl('http://x.lt/i.jpg'));
    }

    public function testHttpRejectedWhenSslDisabled(): void
    {
        Configuration::$testValues['PS_SSL_ENABLED'] = 0;
        $this->assertNull(CatalogMapper::httpsUrl('http://x.lt/i.jpg'));
    }

    public function testHttpsPassesThrough(): void
    {
        $this->assertSame('https://x.lt/i.jpg', CatalogMapper::httpsUrl('https://x.lt/i.jpg'));
    }

    // -- SQL never mixes MySQL NOW() with PHP-written timestamps -------------

    /**
     * PHP date() writes shop-timezone (Europe/Vilnius) values while NOW()
     * uses the MySQL server zone (Europe/Berlin) - they must never be mixed.
     */
    public function testNoNowInSqlSources(): void
    {
        $files = [
            'src/Outbox/Outbox.php',
            'src/Outbox/OutboxProcessor.php',
            'src/Command/CleanupCouponsCommand.php',
            'src/Service/CouponService.php',
            'src/Service/SubscriptionService.php',
            'src/Service/CatalogSyncService.php',
        ];
        foreach ($files as $f) {
            $code = (string) file_get_contents(dirname(__DIR__) . '/' . $f);
            $this->assertSame(0, preg_match('/NOW\s*\(/i', $code), "NOW() found in $f - use PHP date() to match PHP-written timestamps");
        }
    }
}
