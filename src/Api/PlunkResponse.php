<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * Response wrapper for Plunk API calls.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

namespace PlunkConnect\Api;

class PlunkResponse
{
    /** @var int HTTP status code (0 on network failure) */
    public $statusCode;

    /** @var array<string, mixed>|null Decoded JSON body, null when not JSON */
    public $body;

    /** @var string Raw body */
    public $rawBody;

    /** @var int|null Seconds from Retry-After header, when present */
    public $retryAfter;

    /** @var string|null Transport-level error (curl), without PII */
    public $transportError;

    /**
     * @param array<string, mixed>|null $body
     */
    public function __construct(
        int $statusCode,
        ?array $body,
        string $rawBody,
        ?int $retryAfter = null,
        ?string $transportError = null,
    ) {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->rawBody = $rawBody;
        $this->retryAfter = $retryAfter;
        $this->transportError = $transportError;
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isRateLimited(): bool
    {
        return 429 === $this->statusCode;
    }

    /**
     * A 409 from the Idempotency-Key middleware means the same outbox message was
     * already accepted by Plunk — for the worker that is a success, not a failure.
     */
    public function isIdempotencyConflict(): bool
    {
        if (409 !== $this->statusCode || !is_array($this->body)) {
            return false;
        }

        $code = $this->body['code'] ?? ($this->body['error']['code'] ?? null);

        return 'IDEMPOTENCY_KEY_REUSED' === $code || 'idempotency_key_reused' === $code
            || (isset($this->body['error']) && is_string($this->body['error'])
                && false !== stripos($this->body['error'], 'Idempotency-Key'));
    }

    /**
     * Client error that will never succeed on retry (all 4xx except 408/409/429).
     */
    public function isPermanentError(): bool
    {
        if (408 === $this->statusCode || $this->isIdempotencyConflict() || $this->isRateLimited()) {
            return false;
        }

        return $this->statusCode >= 400 && $this->statusCode < 500;
    }
}
