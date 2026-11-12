<?php

/**
 * Newsletter subscription sync, PrestaShop <-> Plunk.
 *
 * PS -> Plunk (via outbox):
 *   double opt-in ON : `newsletter.signup_requested` (subscribed=false)
 *   double opt-in OFF: `newsletter.subscribed`       (subscribed=true)
 *   opt-out          : `newsletter.unsubscribed`     (subscribed=false)
 *
 * Plunk -> PS (webhook): contact.subscribed / contact.unsubscribed update
 * ps_customer.newsletter and ps_emailsubscription.active idempotently.
 */

namespace PlunkConnect\Service;

use PlunkConnect\Config;
use PlunkConnect\Mapper\CustomerMapper;
use PlunkConnect\Outbox\Outbox;

final class SubscriptionService
{
    /**
     * A new or repeated newsletter signup. `subscribed=false` is sent
     * explicitly so Plunk never silently subscribes an existing
     * unsubscribed contact.
     */
    public static function signup(string $email, array $extra = [], ?\Customer $customer = null): void
    {
        if (!self::enabled()) {
            return;
        }
        $idShop = (int) (\Context::getContext()->shop->id ?? 0);
        $idLang = $customer ? (int) $customer->id_lang : (int) (\Context::getContext()->language->id ?? 0);

        $data = CustomerMapper::base($idLang, $idShop);
        $data['source'] = 'prestashop';
        $data['consentSource'] = (string) Config::get(Config::CONSENT_SOURCE, 'website_signup', $idShop);
        $data['consentRequestedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        $data['consentIp'] = (string) (\Tools::getRemoteAddr() ?: '');
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if ('' !== $referer) {
            $data['consentReferer'] = \Tools::substr((string) $referer, 0, 500);
        }
        if ($customer && $customer->id) {
            $data['customerId'] = (int) $customer->id;
            $data['firstName'] = (string) $customer->firstname;
            $data['lastName'] = (string) $customer->lastname;
        }
        foreach ($extra as $key => $value) {
            if (is_scalar($value) && !isset($data[$key])) {
                $data[$key] = $value;
            }
        }

        $doubleOptin = Config::getBool(Config::FEAT_DOUBLE_OPTIN, $idShop);
        Outbox::enqueue('track', [
            'event' => $doubleOptin ? 'newsletter.signup_requested' : 'newsletter.subscribed',
            'email' => $email,
            'subscribed' => !$doubleOptin,
            'data' => $data,
        ], 'signup:' . $email);
    }

    /**
     * Opt-out inside PrestaShop (customer unchecked the newsletter box).
     */
    public static function unsubscribe(string $email, int $idLang = 0): void
    {
        if (!self::enabled()) {
            return;
        }
        Outbox::enqueue('track', [
            'event' => 'newsletter.unsubscribed',
            'email' => $email,
            'subscribed' => false,
            'data' => CustomerMapper::base($idLang ?: (int) \Context::getContext()->language->id),
        ]);
    }

    /**
     * Apply a Plunk webhook to PrestaShop subscription sources.
     * Idempotent: UPDATEs are no-ops when the flag already matches.
     *
     * @return array{customer_rows:int,emailsubscription_rows:int,matched:int}
     */
    public static function applyWebhook(string $email, bool $subscribed, ?int $idShop = null): array
    {
        $flag = $subscribed ? 1 : 0;
        $db = \Db::getInstance();
        $shopFilter = '';
        if (null !== $idShop) {
            $shopFilter = ' AND `id_shop` = ' . $idShop;
        }

        // matched = rows that exist (affected_rows stays 0 when the value
        // is unchanged, which looks like "contact missing" in the response).
        $customerMatched = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer`
             WHERE `email` = \'' . pSQL($email) . '\'' . $shopFilter
        );

        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'customer`
             SET `newsletter` = ' . $flag . ', `date_upd` = \'' . pSQL(date('Y-m-d H:i:s')) . '\'
             WHERE `email` = \'' . pSQL($email) . '\'' . $shopFilter
        );
        $customerRows = (int) $db->Affected_Rows();

        $guestRows = 0;
        $guestMatched = 0;
        // ps_emailsubscription exists only when the module is installed.
        if ($db->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'emailsubscription\'')) {
            $guestMatched = (int) $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'emailsubscription`
                 WHERE `email` = \'' . pSQL($email) . '\'' . $shopFilter
            );
            $db->execute(
                'UPDATE `' . _DB_PREFIX_ . 'emailsubscription`
                 SET `active` = ' . $flag . '
                 WHERE `email` = \'' . pSQL($email) . '\'' . $shopFilter
            );
            $guestRows = (int) $db->Affected_Rows();
        }

        return [
            'customer_rows' => $customerRows,
            'emailsubscription_rows' => $guestRows,
            'matched' => $customerMatched + $guestMatched,
        ];
    }

    /**
     * Guest rows from ps_emailsubscription for backfill/reconcile.
     */
    public static function guestSubscribers(string $since, ?int $idShop = null): array
    {
        if (!\Db::getInstance()->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'emailsubscription\'')) {
            return [];
        }
        $shopFilter = null !== $idShop ? ' AND `id_shop` = ' . $idShop : '';

        return \Db::getInstance()->executeS(
            'SELECT `email`, `id_lang`, `id_shop`, `newsletter_date_add`, `active`
             FROM `' . _DB_PREFIX_ . 'emailsubscription`
             WHERE `newsletter_date_add` >= \'' . pSQL($since) . '\'' . $shopFilter
        ) ?: [];
    }

    /**
     * Registered customers with newsletter=1 for backfill/reconcile.
     */
    public static function customerSubscribers(string $since, ?int $idShop = null): array
    {
        $shopFilter = null !== $idShop ? ' AND `id_shop` = ' . $idShop : '';

        return \Db::getInstance()->executeS(
            'SELECT `email`, `id_lang`, `id_shop`, `newsletter_date_add`, `firstname`, `lastname`, `id_customer`
             FROM `' . _DB_PREFIX_ . 'customer`
             WHERE `newsletter` = 1 AND `newsletter_date_add` >= \'' . pSQL($since) . '\'' . $shopFilter
        ) ?: [];
    }

    private static function enabled(): bool
    {
        return Config::getBool(Config::FEAT_SUBSCRIPTIONS) && Config::isConfigured();
    }
}
