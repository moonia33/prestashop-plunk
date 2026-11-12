<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * Exponential retry schedule and dead-letter rules for outbox messages.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

namespace PlunkConnect\Outbox;

class RetryPolicy
{
    /** Delay after the Nth failure (1-based index): 1, 5, 15, 60 min, 6 h, then 6 h repeats. */
    private const DELAYS_SECONDS = [60, 300, 900, 3600, 21600];

    /** After this many failed attempts a message is dead. */
    public const MAX_ATTEMPTS = 8;

    /**
     * Seconds to wait before the next attempt after failure number $attempts.
     */
    public function delayForAttempt(int $attempts): int
    {
        $index = min(max($attempts, 1), count(self::DELAYS_SECONDS)) - 1;

        return self::DELAYS_SECONDS[$index];
    }

    /**
     * Whether the message should be retried at all after this failure.
     *
     * @param int $attempts Attempt count including the failure that just happened
     */
    public function shouldRetry(int $attempts): bool
    {
        return $attempts < self::MAX_ATTEMPTS;
    }
}
