<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * PII masking for logs: "jonas@gmail.com" -> "j***@gmail.com".
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

namespace PlunkConnect\Util;

class EmailMask
{
    public static function mask(string $email): string
    {
        $atPos = strrpos($email, '@');
        if (false === $atPos || 0 === $atPos) {
            return '***';
        }

        $local = substr($email, 0, $atPos);
        $domain = substr($email, $atPos);

        return substr($local, 0, 1) . '***' . $domain;
    }
}
