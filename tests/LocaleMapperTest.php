<?php

declare(strict_types=1);

namespace PlunkConnect\Tests;

use PHPUnit\Framework\TestCase;
use PlunkConnect\Config\LocaleMapper;

class LocaleMapperTest extends TestCase
{
    public function testExplicitMapWins(): void
    {
        $mapper = new LocaleMapper([5 => 'en'], 'lt');
        $this->assertSame('en', $mapper->localeForLang(5, 'ru'));
    }

    public function testIsoCodeUsedWhenUnmapped(): void
    {
        $mapper = new LocaleMapper([], 'lt');
        $this->assertSame('lv', $mapper->localeForLang(2, 'lv'));
        $this->assertSame('en', $mapper->localeForLang(4, 'en'));
    }

    public function testRussianFallsBack(): void
    {
        // The absolute rule: 'ru' is never emitted.
        $mapper = new LocaleMapper([], 'lt');
        $this->assertSame('lt', $mapper->localeForLang(5, 'ru'));

        $mapper2 = new LocaleMapper([], 'en');
        $this->assertSame('en', $mapper2->localeForLang(5, 'ru'));
    }

    public function testRussianMapEntryIgnored(): void
    {
        // Even a hand-crafted map containing 'ru' is rejected.
        $mapper = new LocaleMapper([5 => 'ru'], 'lt');
        $this->assertSame('lt', $mapper->localeForLang(5, 'ru'));
        $this->assertSame('pl', $mapper->localeForLang(5, 'pl')); // map rejected -> falls back to iso
    }

    public function testInvalidMapEntryIgnored(): void
    {
        $mapper = new LocaleMapper([1 => 'xx', 2 => 'LT'], 'et');
        $this->assertSame('et', $mapper->localeForLang(1, ''));
        $this->assertSame('lt', $mapper->localeForLang(2, '')); // normalized to lowercase
    }

    public function testInvalidFallbackDefaultsToLt(): void
    {
        $mapper = new LocaleMapper([], 'ru');
        $this->assertSame('lt', $mapper->getFallback());
        $this->assertSame('lt', $mapper->localeForLang(9, 'ru'));
    }

    public function testAllowedLocales(): void
    {
        $this->assertTrue(LocaleMapper::isAllowedLocale('lt'));
        $this->assertFalse(LocaleMapper::isAllowedLocale('ru'));
        $this->assertFalse(LocaleMapper::isAllowedLocale(''));
    }
}
