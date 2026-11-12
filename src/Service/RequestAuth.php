<?php

/**
 * Authentication helpers for the module's front controllers:
 *  - signed requests (X-Plunk-Timestamp + X-Plunk-Signature HMAC)
 *  - static Bearer fallback when the caller cannot compute HMAC
 *  - simple token checks for cron/feed endpoints
 */

namespace PlunkConnect\Service;

use PlunkConnect\Config;
use PlunkConnect\Security\HmacService;

final class RequestAuth
{
    /**
     * Webhook/coupon auth: HMAC headers, falling back to a static Bearer
     * token equal to the webhook secret (documented in README).
     */
    public static function verifySignedRequest(string $rawBody): bool
    {
        $secret = (string) Config::get(Config::WEBHOOK_SECRET);
        if ('' === $secret) {
            return false;
        }

        $timestamp = self::header('X-Plunk-Timestamp');
        $signature = self::header('X-Plunk-Signature');
        if (null !== $timestamp && null !== $signature) {
            return (new HmacService($secret))->verifyWebhook($timestamp, $rawBody, $signature);
        }

        $bearer = self::bearerToken();

        return null !== $bearer && hash_equals($secret, $bearer);
    }

    /**
     * Constant-time bearer token check (feed token, cron token...).
     */
    public static function checkBearer(string $configKey): bool
    {
        $expected = (string) Config::get($configKey);
        if ('' === $expected) {
            return false;
        }
        $token = self::bearerToken();

        return null !== $token && hash_equals($expected, $token);
    }

    /**
     * Cron token may arrive as Bearer or ?token=.
     */
    public static function checkCronToken(): bool
    {
        $expected = (string) Config::get(Config::CRON_TOKEN);
        if ('' === $expected) {
            return false;
        }
        $token = self::bearerToken() ?: (string) \Tools::getValue('token');

        return '' !== $token && hash_equals($expected, $token);
    }

    public static function bearerToken(): ?string
    {
        $header = self::header('Authorization');
        if (null !== $header && preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return trim($m[1]);
        }

        return null;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $k => $v) {
                if (0 === strcasecmp((string) $k, $name)) {
                    return (string) $v;
                }
            }
        }

        return null;
    }
}
