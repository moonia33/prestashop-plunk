<?php

/**
 * id_lang -> Plunk locale mapping.
 *
 * Plunk only understands lt, lv, et, en, pl. `ru` (or anything else) falls
 * back to the configured fallback locale; `ru` can never be a target.
 */

namespace PlunkConnect\Mapper;

use PlunkConnect\Config;

final class LocaleMapper
{
    public const ALLOWED = ['lt', 'lv', 'et', 'en', 'pl'];
    public const DEFAULT_FALLBACK = 'lt';

    /**
     * Resolve the Plunk locale for a PrestaShop language id.
     */
    public static function forLang(int $idLang, ?int $idShop = null): string
    {
        $map = Config::getJson(Config::LOCALE_MAP, $idShop);
        if (isset($map[$idLang]) && self::isAllowed($map[$idLang])) {
            return $map[$idLang];
        }

        $iso = strtolower((string) \Language::getIsoById($idLang));
        if (self::isAllowed($iso)) {
            return $iso;
        }

        return self::fallback($idShop);
    }

    /**
     * Configured fallback for unmappable languages (e.g. ru -> lt).
     * The result itself is always an allowed locale.
     */
    public static function fallback(?int $idShop = null): string
    {
        $fallback = strtolower((string) Config::get(Config::LOCALE_FALLBACK, self::DEFAULT_FALLBACK, $idShop));

        return self::isAllowed($fallback) ? $fallback : self::DEFAULT_FALLBACK;
    }

    public static function isAllowed(string $locale): bool
    {
        return in_array(strtolower($locale), self::ALLOWED, true);
    }

    /**
     * id_lang -> iso map of shop languages for the admin mapping table.
     */
    public static function shopLanguages(?int $idShop = null): array
    {
        $out = [];
        foreach (\Language::getLanguages(true, $idShop) as $lang) {
            $out[(int) $lang['id_lang']] = strtolower((string) $lang['iso_code']);
        }

        return $out;
    }
}
