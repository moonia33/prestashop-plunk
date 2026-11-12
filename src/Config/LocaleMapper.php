<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * Maps PrestaShop languages (id_lang / iso_code) to Plunk contact locales.
 * Hard rule: 'ru' is never emitted — it always resolves to the configured
 * fallback locale.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

namespace PlunkConnect\Config;

class LocaleMapper
{
    /** Locales a shop may map to. 'ru' is deliberately absent. */
    public const ALLOWED_LOCALES = ['lt', 'lv', 'et', 'en', 'pl', 'de', 'fr', 'es', 'it', 'uk'];

    /** @var array<int, string> id_lang => locale from configuration */
    private $map;

    /** @var string Configured fallback (validated against ALLOWED_LOCALES) */
    private $fallback;

    /**
     * @param array<int, string> $map id_lang => locale
     * @param string $fallback fallback locale
     */
    public function __construct(array $map, string $fallback)
    {
        $this->map = [];
        foreach ($map as $idLang => $locale) {
            $locale = strtolower(trim((string) $locale));
            if (self::isAllowedLocale($locale)) {
                $this->map[(int) $idLang] = $locale;
            }
        }

        $this->fallback = self::isAllowedLocale(strtolower(trim($fallback))) ? strtolower(trim($fallback)) : 'lt';
    }

    public static function isAllowedLocale(string $locale): bool
    {
        return in_array(strtolower($locale), self::ALLOWED_LOCALES, true);
    }

    /**
     * Resolves the locale for a PrestaShop language.
     *
     * Priority: explicit map entry > language iso_code (if allowed) > fallback.
     * 'ru' is rejected at every stage.
     *
     * @param int $idLang ps_lang.id_lang
     * @param string $isoCode ps_lang.iso_code (e.g. 'lt', 'ru')
     */
    public function localeForLang(int $idLang, string $isoCode = ''): string
    {
        if (isset($this->map[$idLang])) {
            return $this->map[$idLang];
        }

        $iso = strtolower(trim($isoCode));
        if ('' !== $iso && 'ru' !== $iso && self::isAllowedLocale($iso)) {
            return $iso;
        }

        return $this->fallback;
    }

    public function getFallback(): string
    {
        return $this->fallback;
    }
}
