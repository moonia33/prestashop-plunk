<?php

/**
 * Central registry of module configuration keys.
 *
 * All values are stored per-shop via Configuration (multistore aware):
 * each shop can point to a different Plunk project with its own keys.
 */

namespace PlunkConnect;

final class Config
{
    public const PREFIX = 'PLUNKCONNECT_';

    public const API_URL = self::PREFIX . 'API_URL';
    public const PUBLIC_KEY = self::PREFIX . 'PUBLIC_KEY';
    public const SECRET_KEY = self::PREFIX . 'SECRET_KEY';
    public const WEBHOOK_SECRET = self::PREFIX . 'WEBHOOK_SECRET';
    public const FEED_TOKEN = self::PREFIX . 'FEED_TOKEN';
    public const CRON_TOKEN = self::PREFIX . 'CRON_TOKEN';
    public const COUPON_SECRET = self::PREFIX . 'COUPON_SECRET';
    public const COUPON_RULES = self::PREFIX . 'COUPON_RULES';

    /** JSON: {"1":"lt","2":"lv",...} mapping id_lang => Plunk locale. */
    public const LOCALE_MAP = self::PREFIX . 'LOCALE_MAP';
    public const LOCALE_FALLBACK = self::PREFIX . 'LOCALE_FALLBACK';

    /** JSON: {"paid":[2],"shipped":[4],...} mapping event => [id_order_state]. */
    public const ORDERSTATE_MAP = self::PREFIX . 'ORDERSTATE_MAP';

    /** id_feature whose values are the shop's activities (e.g. "Žvejybos rūšis"); 0 = off. */
    public const ACTIVITY_FEATURE_ID = self::PREFIX . 'ACTIVITY_FEATURE_ID';

    public const FEAT_SUBSCRIPTIONS = self::PREFIX . 'FEAT_SUBSCRIPTIONS';
    public const FEAT_DOUBLE_OPTIN = self::PREFIX . 'FEAT_DOUBLE_OPTIN';
    public const FEAT_VIEWS = self::PREFIX . 'FEAT_VIEWS';
    public const FEAT_CART = self::PREFIX . 'FEAT_CART';
    public const FEAT_ORDERS = self::PREFIX . 'FEAT_ORDERS';
    public const FEAT_FEED = self::PREFIX . 'FEAT_FEED';
    public const FEAT_COUPONS = self::PREFIX . 'FEAT_COUPONS';
    public const FEAT_BACKINSTOCK = self::PREFIX . 'FEAT_BACKINSTOCK';
    public const FEAT_PK_CAPTURE = self::PREFIX . 'FEAT_PK_CAPTURE';
    public const FEAT_CATALOG_SYNC = self::PREFIX . 'FEAT_CATALOG_SYNC';
    public const CATALOG_LAST_SYNC = self::PREFIX . 'CATALOG_LAST_SYNC';
    public const CATALOG_FULL_SYNC_AT = self::PREFIX . 'CATALOG_FULL_SYNC_AT';

    public const CONSENT_SOURCE = self::PREFIX . 'CONSENT_SOURCE';
    public const SHOP_CODE = self::PREFIX . 'SHOP_CODE';
    public const CONSENT_COOKIE = self::PREFIX . 'CONSENT_COOKIE';
    public const CONSENT_COOKIE_VALUE = self::PREFIX . 'CONSENT_COOKIE_VALUE';

    public const DB_VERSION = self::PREFIX . 'DB_VERSION';

    /** All keys deleted on uninstall / reset. */
    public const ALL_KEYS = [
        self::API_URL, self::PUBLIC_KEY, self::SECRET_KEY,
        self::WEBHOOK_SECRET, self::FEED_TOKEN, self::CRON_TOKEN,
        self::COUPON_SECRET, self::COUPON_RULES,
        self::LOCALE_MAP, self::LOCALE_FALLBACK, self::ORDERSTATE_MAP, self::ACTIVITY_FEATURE_ID,
        self::FEAT_SUBSCRIPTIONS, self::FEAT_DOUBLE_OPTIN, self::FEAT_VIEWS,
        self::FEAT_CART, self::FEAT_ORDERS, self::FEAT_FEED, self::FEAT_COUPONS,
        self::FEAT_BACKINSTOCK, self::FEAT_PK_CAPTURE,
        self::FEAT_CATALOG_SYNC, self::CATALOG_LAST_SYNC, self::CATALOG_FULL_SYNC_AT,
        self::CONSENT_SOURCE, self::SHOP_CODE,
        self::CONSENT_COOKIE, self::CONSENT_COOKIE_VALUE, self::DB_VERSION,
    ];

    private function __construct()
    {
    }

    /**
     * Shop-scoped config read. Falls back to shop-group and global values,
     * mirroring PrestaShop's own Configuration lookup chain.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public static function get(string $key, $default = '', ?int $idShop = null)
    {
        if (null === $idShop) {
            $shop = \Context::getContext()->shop ?? null;
            $idShop = $shop ? (int) $shop->id : null;
        }

        $value = \Configuration::get($key, null, null, $idShop);
        if (false === $value && null !== $idShop) {
            $value = \Configuration::get($key);
        }

        return false === $value ? $default : $value;
    }

    /**
     * Shop-scoped config write. In multistore the admin "all shops" context
     * writes the global row; a single-shop context writes the shop row.
     *
     * @param mixed $value
     */
    public static function set(string $key, $value): void
    {
        $idShop = null;
        if (\Shop::CONTEXT_SHOP === \Shop::getContext()) {
            $idShop = (int) \Shop::getContextShopID();
        }
        \Configuration::updateValue($key, $value, false, null, $idShop);
    }

    public static function getBool(string $key, ?int $idShop = null): bool
    {
        return (bool) self::get($key, false, $idShop);
    }

    public static function getJson(string $key, ?int $idShop = null): array
    {
        $raw = self::get($key, '', $idShop);
        if (!is_string($raw) || '' === $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * True when the module has enough configuration to talk to Plunk.
     * Public key is enough for /v1/track; secret features degrade gracefully.
     */
    public static function isConfigured(?int $idShop = null): bool
    {
        return '' !== (string) self::get(self::API_URL, '', $idShop)
            && '' !== (string) self::get(self::PUBLIC_KEY, '', $idShop);
    }

    public static function generateSecret(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
