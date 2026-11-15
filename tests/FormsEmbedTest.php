<?php

namespace PlunkConnect\Tests;

use PHPUnit\Framework\TestCase;
use PlunkConnect\Service\FormsService;

class FormsEmbedTest extends TestCase
{
    private const CONFIG = ['key' => 'pk_test', 'locale' => 'lt', 'subscribed' => false, 'customer' => true];

    public function testRendersConfigBeforeScript(): void
    {
        $html = FormsService::render('https://api.example.com/', self::CONFIG);

        $this->assertSame(
            '<script>window.PlunkForms={"key":"pk_test","locale":"lt","subscribed":false,"customer":true};</script>'
            . '<script src="https://api.example.com/forms/embed.js" async></script>',
            $html
        );
    }

    public function testValuesCannotBreakOutOfTheScriptTag(): void
    {
        $html = FormsService::render('https://api.example.com', ['key' => '</script><script>alert(1)</script>'] + self::CONFIG);

        $this->assertSame(2, substr_count($html, '</script>'));
        $this->assertStringNotContainsString('<script>alert', $html);
    }

    public function testRejectsNonHttpApiUrl(): void
    {
        $this->assertSame('', FormsService::render('javascript:alert(1)//', self::CONFIG));
        $this->assertSame('', FormsService::render('', self::CONFIG));
    }

    public function testEscapesScriptSrcAttribute(): void
    {
        $html = FormsService::render('https://api.example.com/"onload="x', self::CONFIG);

        $this->assertStringNotContainsString('"onload="', $html);
        $this->assertStringContainsString('&quot;onload=&quot;', $html);
    }
}
