<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * Authenticates inbound Plunk webhook calls.
 *
 * The Plunk workflow WEBHOOK step (verified against useplunk/plunk @ next,
 * WorkflowExecutionService::executeWebhook) only renders static header
 * strings — it cannot compute an HMAC of the request body. The supported
 * scheme is therefore a static bearer token:
 *
 *   Authorization: Bearer <webhook secret>
 *
 * An HMAC scheme (X-Plunk-Timestamp + X-Plunk-Signature =
 * hex(HMAC_SHA256(secret, timestamp + "." + rawBody)), ±300 s window) is
 * also accepted so a future Plunk version — or a different sender able to
 * sign — can use it.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

namespace PlunkConnect\Security;

class WebhookAuthenticator
{
    /** Allowed clock skew for the HMAC timestamp, in seconds. */
    public const TIMESTAMP_TOLERANCE = 300;

    /** @var string */
    private $secret;

    /** @var callable(): int Injected clock for tests */
    private $clock;

    /**
     * @param callable|null $clock returns current unix timestamp
     */
    public function __construct(string $secret, ?callable $clock = null)
    {
        $this->secret = $secret;
        $this->clock = $clock ?: static function (): int {
            return time();
        };
    }

    /**
     * @param string $rawBody Exact request body as received
     * @param array<string, string> $headers Normalized header map (lowercase names)
     */
    public function isValid(string $rawBody, array $headers): bool
    {
        if ('' === $this->secret) {
            return false;
        }

        // HMAC path: both signature headers must be present and valid.
        $signature = $this->header($headers, 'x-plunk-signature');
        $timestamp = $this->header($headers, 'x-plunk-timestamp');
        if (null !== $signature && null !== $timestamp) {
            return $this->isValidSignature($signature, $timestamp, $rawBody);
        }

        // Bearer path.
        $authorization = $this->header($headers, 'authorization');
        if (null !== $authorization && 0 === stripos($authorization, 'Bearer ')) {
            $token = substr($authorization, 7);

            return hash_equals($this->secret, $token);
        }

        return false;
    }

    private function isValidSignature(string $signature, string $timestamp, string $rawBody): bool
    {
        if (!ctype_digit($timestamp)) {
            return false;
        }

        if (abs(call_user_func($this->clock) - (int) $timestamp) > self::TIMESTAMP_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @param array<string, string> $headers lowercase header names
     */
    private function header(array $headers, string $name): ?string
    {
        return isset($headers[$name]) ? trim((string) $headers[$name]) : null;
    }
}
