<?php

/**
 * Marketing-consent gate for behavioural tracking (page views).
 *
 * Newsletter subscriptions and orders are contractual data and are always
 * sent; passive view tracking only fires when the shop's CMP cookie matches
 * the configured name/value. With no cookie configured, tracking is on.
 */

namespace PlunkConnect\Service;

use PlunkConnect\Config;

final class ConsentService
{
    public static function marketingAllowed(?int $idShop = null): bool
    {
        $cookieName = trim((string) Config::get(Config::CONSENT_COOKIE, '', $idShop));
        if ('' === $cookieName) {
            return true;
        }
        $expected = trim((string) Config::get(Config::CONSENT_COOKIE_VALUE, '', $idShop));
        $actual = $_COOKIE[$cookieName] ?? null;
        if (null === $actual) {
            return false;
        }

        // Expected "marketing" matches "marketing" and "all,marketing,nec."
        $values = array_map('trim', explode(',', (string) $actual));
        if (in_array($actual, ['1', 'true', 'all'], true)) {
            return true;
        }

        return '' !== $expected && in_array($expected, $values, true);
    }
}
