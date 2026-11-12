<?php

/**
 * psgdpr integration: erasure queued through the outbox (lookup + delete),
 * export returns a description of what is sent to Plunk.
 */

namespace PlunkConnect\Service;

use PlunkConnect\Outbox\Outbox;

final class GdprService
{
    /**
     * actionDeleteGDPRCustomer handler.
     */
    public static function deleteCustomer(string $email): void
    {
        if (!\Validate::isEmail($email)) {
            return;
        }
        Outbox::enqueue('lookup_delete', ['email' => strtolower($email)], 'gdpr:' . md5($email));
    }

    /**
     * actionExportGDPRData handler - describes the data sent to Plunk.
     */
    public static function exportData(string $email): array
    {
        return [
            'plunkconnect' => [
                'email' => $email,
                'sent_to_plunk' => [
                    'email',
                    'firstName',
                    'lastName',
                    'locale',
                    'consentSource',
                    'consentRequestedAt',
                    'consentIp',
                    'consentReferer',
                    'shopCode',
                    'customerId',
                    'ordersCount',
                    'totalSpent',
                    'lastOrderAt',
                    'cartItemsCount',
                    'recentlyViewed',
                    'lastCouponCode',
                ],
            ],
        ];
    }
}
