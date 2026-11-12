<?php

/**
 * Resolves "who is this visitor" for behavioural events:
 *  1. logged-in / guest-checkout customer (customer->email)
 *  2. a `plunk_id` cookie left by a previous Plunk email click (?pk_e=...)
 *  3. anonymous - views accumulate in the session cookie and flush as a
 *     single event once the visitor becomes identified.
 */

namespace PlunkConnect\Service;

use PlunkConnect\Config;
use PlunkConnect\Security\HmacService;

final class IdentityService
{
    public const COOKIE_NAME = 'plunk_id';
    public const COOKIE_DAYS = 365;
    private const ANON_COOKIE = 'plunk_views';
    private const ANON_MAX = 20;

    /**
     * Email address for the current visitor, or null when anonymous.
     */
    public static function email(?\Context $context = null): ?string
    {
        $context = $context ?: \Context::getContext();

        if ($context->customer && $context->customer->id && !empty($context->customer->email)
            && \Validate::isEmail($context->customer->email)) {
            return strtolower((string) $context->customer->email);
        }
        // Guest checkout: email lives on the customer row before login.
        if ($context->cart && $context->cart->id_customer) {
            $customer = new \Customer((int) $context->cart->id_customer);
            if (\Validate::isLoadedObject($customer) && \Validate::isEmail($customer->email)) {
                return strtolower((string) $customer->email);
            }
        }

        return self::emailFromCookie();
    }

    /**
     * Verified email from the `plunk_id` cookie (HMAC-signed token).
     */
    public static function emailFromCookie(): ?string
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!$token) {
            return null;
        }
        $hmac = self::hmac();

        return $hmac ? $hmac->decodeEmailToken((string) $token) : null;
    }

    /**
     * Capture `?pk_e=<token>` from a Plunk email link and persist a
     * HttpOnly/Secure/SameSite=Lax cookie for a year.
     */
    public static function captureEmailToken(): void
    {
        if (!Config::getBool(Config::FEAT_PK_CAPTURE)) {
            return;
        }
        $token = \Tools::getValue('pk_e');
        if (!$token || !is_string($token)) {
            return;
        }
        $hmac = self::hmac();
        if (!$hmac || !$hmac->decodeEmailToken($token)) {
            return;
        }
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE_NAME, $token, [
            'expires' => time() + self::COOKIE_DAYS * 86400,
            'path' => '/',
            'secure' => \Tools::usingSecureMode(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Build an email token for templates (admin may paste into Plunk links).
     */
    public static function tokenFor(string $email): ?string
    {
        $hmac = self::hmac();

        return $hmac ? $hmac->encodeEmailToken($email) : null;
    }

    /**
     * Anonymous views: keep last N product ids in the (encrypted) PS cookie.
     */
    public static function recordAnonymousView(int $idProduct): void
    {
        $context = \Context::getContext();
        $list = self::anonymousViews();
        $list = array_values(array_filter($list, fn ($id) => $id !== $idProduct));
        $list[] = $idProduct;
        $context->cookie->{self::ANON_COOKIE} = json_encode(array_slice($list, -self::ANON_MAX));
    }

    /**
     * Drain anonymous views; caller flushes them as one event for `email`.
     */
    public static function drainAnonymousViews(): array
    {
        $context = \Context::getContext();
        $views = self::anonymousViews();
        $context->cookie->{self::ANON_COOKIE} = '';

        return $views;
    }

    public static function anonymousViews(): array
    {
        $context = \Context::getContext();
        $raw = isset($context->cookie->{self::ANON_COOKIE}) ? (string) $context->cookie->{self::ANON_COOKIE} : '';
        $decoded = '' !== $raw ? json_decode($raw, true) : [];

        return is_array($decoded) ? array_values(array_map('intval', $decoded)) : [];
    }

    /**
     * Simple bot heuristic on User-Agent; admin employees excluded via
     * the employee cookie that PrestaShop sets on the back office session.
     */
    public static function isBot(): bool
    {
        $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ('' === $ua) {
            return true;
        }

        return (bool) preg_match(
            '/bot|crawl|spider|slurp|facebookexternal|headless|phantom|curl\/|wget\/|python-requests|monitoring|uptime|pingdom/i',
            $ua
        );
    }

    private static function hmac(): ?HmacService
    {
        $secret = (string) Config::get(Config::WEBHOOK_SECRET);
        if ('' === $secret) {
            return null;
        }

        return new HmacService($secret);
    }
}
