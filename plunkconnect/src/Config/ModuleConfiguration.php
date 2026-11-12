<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * Typed access to module configuration. All values are stored per shop
 * (multistore safe) via Configuration::get/updateValue with shop context.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

namespace PlunkConnect\Config;

use PlunkConnect\Api\PlunkClient;

class ModuleConfiguration
{
    public const API_URL = 'PLUNKCONNECT_API_URL';
    public const PUBLIC_KEY = 'PLUNKCONNECT_PUBLIC_KEY';
    public const SECRET_KEY = 'PLUNKCONNECT_SECRET_KEY';
    public const WEBHOOK_SECRET = 'PLUNKCONNECT_WEBHOOK_SECRET';
    public const CRON_TOKEN = 'PLUNKCONNECT_CRON_TOKEN';
    public const FEED_TOKEN = 'PLUNKCONNECT_FEED_TOKEN';
    public const LOCALE_MAP = 'PLUNKCONNECT_LOCALE_MAP';
    public const LOCALE_FALLBACK = 'PLUNKCONNECT_LOCALE_FALLBACK';
    public const ORDERSTATE_MAP = 'PLUNKCONNECT_ORDERSTATE_MAP';
    public const CONSENT_SOURCE = 'PLUNKCONNECT_CONSENT_SOURCE';
    public const SHOP_CODE = 'PLUNKCONNECT_SHOP_CODE';
    public const CONSENT_COOKIE_NAME = 'PLUNKCONNECT_CONSENT_COOKIE_NAME';
    public const CONSENT_COOKIE_VALUE = 'PLUNKCONNECT_CONSENT_COOKIE_VALUE';

    public const FEAT_NEWSLETTER = 'PLUNKCONNECT_FEAT_NEWSLETTER';
    public const FEAT_DOUBLE_OPTIN = 'PLUNKCONNECT_FEAT_DOUBLE_OPTIN';
    public const FEAT_TRACKING = 'PLUNKCONNECT_FEAT_TRACKING';
    public const FEAT_CART = 'PLUNKCONNECT_FEAT_CART';
    public const FEAT_ORDERS = 'PLUNKCONNECT_FEAT_ORDERS';
    public const FEAT_FEED = 'PLUNKCONNECT_FEAT_FEED';
    public const FEAT_COUPONS = 'PLUNKCONNECT_FEAT_COUPONS';
    public const FEAT_BACKINSTOCK = 'PLUNKCONNECT_FEAT_BACKINSTOCK';

    /** Keys that must never be logged, exported or shown in full. */
    public const SECRET_KEYS = [
        self::PUBLIC_KEY,
        self::SECRET_KEY,
        self::WEBHOOK_SECRET,
        self::CRON_TOKEN,
        self::FEED_TOKEN,
    ];

    /**
     * @param int|null $idShop Shop context; null = current shop
     * @param int|null $idShopGroup Shop group context
     */
    public function get(string $key, ?int $idShop = null, ?int $idShopGroup = null): string
    {
        $value = \Configuration::get($key, null, $idShopGroup, $idShop);

        return false === $value || null === $value ? '' : (string) $value;
    }

    public function set(string $key, string $value, ?int $idShop = null, ?int $idShopGroup = null): bool
    {
        return \Configuration::updateValue($key, $value, false, $idShopGroup, $idShop);
    }

    public function getBool(string $key, ?int $idShop = null, ?int $idShopGroup = null): bool
    {
        return (bool) $this->get($key, $idShop, $idShopGroup);
    }

    public function getApiUrl(?int $idShop = null): string
    {
        return rtrim($this->get(self::API_URL, $idShop), '/');
    }

    /**
     * The module may send API calls only when URL + at least one key are set.
     */
    public function isConfigured(?int $idShop = null): bool
    {
        return '' !== $this->getApiUrl($idShop)
            && ('' !== $this->get(self::PUBLIC_KEY, $idShop) || '' !== $this->get(self::SECRET_KEY, $idShop));
    }

    /**
     * API calls also require the global newsletter feature flag or at least one
     * feature to be enabled; kept separate so hooks can no-op cheaply.
     */
    public function isFeatureEnabled(string $featureKey, ?int $idShop = null): bool
    {
        return $this->isConfigured($idShop) && $this->getBool($featureKey, $idShop);
    }

    public function getShopId(): int
    {
        return (int) \Context::getContext()->shop->id;
    }

    /**
     * Locale map: JSON {id_lang: locale}. Parsed defensively.
     *
     * @return array<int, string>
     */
    public function getLocaleMap(?int $idShop = null): array
    {
        $raw = $this->get(self::LOCALE_MAP, $idShop);
        if ('' === $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $idLang => $locale) {
            if (is_numeric($idLang) && is_string($locale) && '' !== $locale) {
                $map[(int) $idLang] = $locale;
            }
        }

        return $map;
    }

    public function getLocaleFallback(?int $idShop = null): string
    {
        $fallback = $this->get(self::LOCALE_FALLBACK, $idShop);

        return '' !== $fallback ? $fallback : 'lt';
    }

    /**
     * A configured PlunkClient for a shop context.
     */
    public function createClient(?int $idShop = null): PlunkClient
    {
        return new PlunkClient(
            $this->getApiUrl($idShop),
            $this->get(self::PUBLIC_KEY, $idShop),
            $this->get(self::SECRET_KEY, $idShop)
        );
    }

    /**
     * Generates the random tokens on install. Only fills values that are empty
     * so reinstall/upgrade does not rotate working secrets.
     */
    public function generateMissingTokens(?int $idShop = null, ?int $idShopGroup = null): void
    {
        foreach ([self::WEBHOOK_SECRET, self::CRON_TOKEN, self::FEED_TOKEN] as $key) {
            if ('' === $this->get($key, $idShop, $idShopGroup)) {
                $this->set($key, $this->randomToken(32), $idShop, $idShopGroup);
            }
        }
    }

    public function regenerateToken(string $key, ?int $idShop = null, ?int $idShopGroup = null): bool
    {
        if (!in_array($key, [self::WEBHOOK_SECRET, self::CRON_TOKEN, self::FEED_TOKEN], true)) {
            return false;
        }

        return $this->set($key, $this->randomToken(32), $idShop, $idShopGroup);
    }

    public function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Displays a secret masked, e.g. "pk_ab************yz".
     */
    public function maskSecret(string $value): string
    {
        $length = strlen($value);
        if ($length <= 8) {
            return str_repeat('*', max(4, $length));
        }

        return substr($value, 0, 5) . str_repeat('*', $length - 8) . substr($value, -3);
    }
}
