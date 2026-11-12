<?php

/**
 * HMAC-SHA256 signing and verification for webhooks, coupons and
 * cart-restore links. Framework-agnostic (unit-testable).
 */

namespace PlunkConnect\Security;

final class HmacService
{
    public const TIMESTAMP_TOLERANCE = 300; // seconds

    private string $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    public function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }

    public function verify(string $payload, string $signature): bool
    {
        if ('' === $signature || !ctype_xdigit($signature)) {
            return false;
        }

        return hash_equals($this->sign($payload), strtolower($signature));
    }

    /**
     * Signed webhook scheme: X-Plunk-Timestamp + X-Plunk-Signature =
     * hex(HMAC_SHA256(secret, timestamp + "." + rawBody)), +-300 s window.
     */
    public function verifyWebhook(string $timestamp, string $rawBody, string $signature): bool
    {
        if (!ctype_digit($timestamp)) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > self::TIMESTAMP_TOLERANCE) {
            return false;
        }

        return $this->verify($timestamp . '.' . $rawBody, $signature);
    }

    /**
     * Email token used in Plunk links (?pk_e=...) so a click identifies the
     * contact in the shop. Token = base64url(email) . "." . hmac(email).
     */
    public function encodeEmailToken(string $email): string
    {
        $b64 = rtrim(strtr(base64_encode(strtolower($email)), '+/', '-_'), '=');

        return $b64 . '.' . $this->sign(strtolower($email));
    }

    public function decodeEmailToken(string $token): ?string
    {
        $dot = strrpos($token, '.');
        if (false === $dot) {
            return null;
        }
        $email = base64_decode(strtr(substr($token, 0, $dot), '-_', '+/'), true);
        $signature = substr($token, $dot + 1);
        if (!is_string($email) || '' === $email || !\Validate::isEmail($email)) {
            return null;
        }

        return $this->verify(strtolower($email), $signature) ? strtolower($email) : null;
    }
}
