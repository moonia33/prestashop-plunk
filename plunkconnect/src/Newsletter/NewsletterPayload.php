<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * Pure builders for the /v1/track payloads the module sends. Kept free of
 * PrestaShop dependencies so they are unit-testable.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

namespace PlunkConnect\Newsletter;

class NewsletterPayload
{
    public const EVENT_SIGNUP_REQUESTED = 'newsletter.signup_requested';
    public const EVENT_SUBSCRIBED = 'newsletter.subscribed';
    public const EVENT_UNSUBSCRIBED = 'newsletter.unsubscribed';

    /**
     * Double opt-in: contact is created unsubscribed, a Plunk workflow sends
     * the confirmation email containing {{subscribeUrl}}.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function signupRequested(string $email, array $data): array
    {
        return [
            'event' => self::EVENT_SIGNUP_REQUESTED,
            'email' => $email,
            'subscribed' => false,
            'data' => $data,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function subscribed(string $email, array $data): array
    {
        return [
            'event' => self::EVENT_SUBSCRIBED,
            'email' => $email,
            'subscribed' => true,
            'data' => $data,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function unsubscribed(string $email, array $data): array
    {
        return [
            'event' => self::EVENT_UNSUBSCRIBED,
            'email' => $email,
            'subscribed' => false,
            'data' => $data,
        ];
    }

    /**
     * Base consent/context fields present on every newsletter event.
     *
     * @param array<string, scalar|null> $extra merged in afterwards (names, customerId…)
     *
     * @return array<string, mixed>
     */
    public static function consentData(
        string $locale,
        string $consentSource,
        string $shopCode,
        string $consentIp = '',
        string $consentReferer = '',
        array $extra = [],
    ): array {
        $data = [
            'locale' => $locale,
            'source' => 'prestashop',
            'consentSource' => $consentSource,
            'consentRequestedAt' => gmdate('c'),
            'shopCode' => $shopCode,
        ];

        if ('' !== $consentIp) {
            $data['consentIp'] = $consentIp;
        }
        if ('' !== $consentReferer) {
            $data['consentReferer'] = $consentReferer;
        }

        foreach ($extra as $key => $value) {
            if (null !== $value && '' !== $value) {
                $data[$key] = $value;
            }
        }

        return $data;
    }
}
