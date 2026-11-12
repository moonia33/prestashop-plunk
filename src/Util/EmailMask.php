<?php

/**
 * PII masking helpers for logs and error messages.
 */

namespace PlunkConnect\Util;

final class EmailMask
{
    /**
     * l***@gmail.com style masking. Never log a raw address.
     */
    public static function mask(?string $email): string
    {
        if (!$email || !strpos($email, '@')) {
            return '(none)';
        }
        [$local, $domain] = explode('@', $email, 2);
        $first = '' !== $local ? $local[0] : '*';

        return $first . '***@' . $domain;
    }

    /**
     * Mask every email-looking substring in a message before persisting it
     * to outbox.last_error.
     */
    public static function scrub(string $message): string
    {
        return (string) preg_replace_callback(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            fn (array $m) => self::mask($m[0]),
            $message
        );
    }
}
