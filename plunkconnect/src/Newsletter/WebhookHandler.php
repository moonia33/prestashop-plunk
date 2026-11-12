<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * Applies subscription changes coming from Plunk (hosted subscribe /
 * unsubscribe pages, bounce, complaint, admin actions) back to PrestaShop.
 *
 * Writes go straight to the DB on purpose: ObjectModel saves would re-fire
 * the object hooks and could echo the same change back to Plunk.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

namespace PlunkConnect\Newsletter;

use PlunkConnect\Util\EmailMask;

class WebhookHandler
{
    /**
     * Extracts {email, subscribed} from the Plunk workflow WEBHOOK step body.
     *
     * Accepted shapes (the body is fully configurable on the Plunk side; all
     * documented variants are tolerated):
     *   {"contact": {"email": "...", "subscribed": true}}          – default payload
     *   {"email": "...", "subscribed": true}                       – custom body
     *   {"email": "...", "event": "contact.subscribed"}            – event-name driven
     *
     * @param array<string, mixed> $body
     *
     * @return array{email: string, subscribed: bool}|null null when the
     *                                                     payload does not carry a subscription decision
     */
    public static function extractSubscription(array $body): ?array
    {
        $email = null;
        $subscribed = null;

        if (isset($body['contact']) && is_array($body['contact'])) {
            if (isset($body['contact']['email']) && is_string($body['contact']['email'])) {
                $email = $body['contact']['email'];
            }
            if (array_key_exists('subscribed', $body['contact'])) {
                $subscribed = (bool) $body['contact']['subscribed'];
            }
        }

        if (null === $email && isset($body['email']) && is_string($body['email'])) {
            $email = $body['email'];
        }
        if (null === $subscribed && array_key_exists('subscribed', $body) && is_bool($body['subscribed'])) {
            $subscribed = $body['subscribed'];
        }

        // Fallback: derive from an explicit event name.
        if (null === $subscribed && isset($body['event']) && is_string($body['event'])) {
            if ('contact.subscribed' === $body['event']) {
                $subscribed = true;
            } elseif ('contact.unsubscribed' === $body['event']) {
                $subscribed = false;
            }
        }

        if (null === $email || null === $subscribed || !\Validate::isEmail($email)) {
            return null;
        }

        return ['email' => $email, 'subscribed' => $subscribed];
    }

    /**
     * Applies the subscription decision to ps_customer and
     * ps_emailsubscription, scoped to the current shop. Idempotent.
     *
     * @return array{email: string, subscribed: bool, changed: string[]}
     */
    public function applySubscription(string $email, bool $subscribed, int $idShop, int $idLang = 0): array
    {
        $changed = [];
        $db = \Db::getInstance();
        $emailSql = pSQL($email);

        // Registered customer(s) with this email in this shop.
        $customerIds = array_map('intval', (array) $db->executeS(
            'SELECT `id_customer` FROM `' . _DB_PREFIX_ . 'customer`
            WHERE `email` = \'' . $emailSql . '\' AND `id_shop` = ' . (int) $idShop,
            true,
            false
        ));

        foreach ($customerIds as $idCustomer) {
            $current = (int) $db->getValue(
                'SELECT `newsletter` FROM `' . _DB_PREFIX_ . 'customer`
                WHERE `id_customer` = ' . $idCustomer
            );

            if ($current !== (int) $subscribed) {
                $fields = ['newsletter' => (int) $subscribed];
                if ($subscribed) {
                    $fields['newsletter_date_add'] = date('Y-m-d H:i:s');
                }
                $db->update('customer', $fields, '`id_customer` = ' . $idCustomer);
                $changed[] = 'customer:' . $idCustomer;
            }
        }

        // Guest row in ps_emailsubscription.
        $guestRow = $db->getRow(
            'SELECT `id`, `active` FROM `' . _DB_PREFIX_ . 'emailsubscription`
            WHERE `email` = \'' . $emailSql . '\' AND `id_shop` = ' . (int) $idShop
        );

        if ($subscribed) {
            if ($guestRow) {
                if (!(int) $guestRow['active']) {
                    $db->update('emailsubscription', ['active' => 1], '`id` = ' . (int) $guestRow['id']);
                    $changed[] = 'emailsubscription:' . $guestRow['id'];
                }
            } else {
                // Mirror Ps_Emailsubscription::registerGuest() semantics.
                $db->insert('emailsubscription', [
                    'id_shop' => $idShop,
                    'id_shop_group' => (int) \Context::getContext()->shop->id_shop_group,
                    'email' => $emailSql,
                    'newsletter_date_add' => date('Y-m-d H:i:s'),
                    'active' => 1,
                    'id_lang' => $idLang > 0 ? $idLang : (int) \Context::getContext()->language->id,
                ]);
                $changed[] = 'emailsubscription:new';
            }
        } elseif ($guestRow) {
            // PrestaShop deletes the guest row on unsubscribe — mirror that.
            $db->delete('emailsubscription', '`id` = ' . (int) $guestRow['id']);
            $changed[] = 'emailsubscription:deleted';
        }

        if (!empty($changed)) {
            \PrestaShopLogger::addLog(
                sprintf(
                    'PlunkConnect: webhook set subscribed=%d for %s (%s)',
                    (int) $subscribed,
                    EmailMask::mask($email),
                    implode(',', $changed)
                ),
                1,
                null,
                'PlunkConnect',
                null,
                true
            );
        }

        return ['email' => $email, 'subscribed' => $subscribed, 'changed' => $changed];
    }
}
