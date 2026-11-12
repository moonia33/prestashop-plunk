<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * GDPR (psgdpr module) integration.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

namespace PlunkConnect\Gdpr;

use PlunkConnect\Config\ModuleConfiguration;
use PlunkConnect\Newsletter\NewsletterSyncService;
use PlunkConnect\Util\EmailMask;

class GdprService
{
    /** @var ModuleConfiguration */
    private $config;

    /** @var NewsletterSyncService */
    private $sync;

    public function __construct(ModuleConfiguration $config, ?NewsletterSyncService $sync = null)
    {
        $this->config = $config;
        $this->sync = $sync ?: new NewsletterSyncService($config);
    }

    /**
     * actionDeleteGDPRCustomer: the contact deletion is queued into the
     * outbox — the hook itself never calls the Plunk API.
     *
     * @param array<string, mixed> $customer psgdpr passes the customer as array
     */
    public function onDeleteCustomer(array $customer, int $idShop): void
    {
        $email = isset($customer['email']) ? (string) $customer['email'] : '';
        if ('' === $email) {
            return;
        }

        $this->sync->scheduleContactDeletion($email, $idShop);

        \PrestaShopLogger::addLog(
            'PlunkConnect: GDPR delete queued for ' . EmailMask::mask($email),
            1,
            null,
            'PlunkConnect',
            null,
            true
        );
    }

    /**
     * actionExportGDPRData: reports which personal data the module sends to
     * Plunk. Must return a JSON string (psgdpr json_decodes the result).
     *
     * @param array<string, mixed> $customer
     */
    public function onExportData(array $customer): string
    {
        $email = isset($customer['email']) ? (string) $customer['email'] : '';

        return json_encode([
            'module' => 'plunkconnect',
            'destination' => 'Plunk (' . ($this->config->getApiUrl() ?: 'not configured') . ')',
            'data' => [
                'email' => $email,
                'firstName' => $customer['firstname'] ?? null,
                'lastName' => $customer['lastname'] ?? null,
                'customerId' => $customer['id_customer'] ?? $customer['id'] ?? null,
                'newsletterConsent' => [
                    'consentSource', 'consentRequestedAt', 'consentIp', 'consentReferer',
                ],
                'locale', 'shopCode',
                'subscriptionEvents' => [
                    'newsletter.signup_requested',
                    'newsletter.subscribed',
                    'newsletter.unsubscribed',
                ],
            ],
        ]) ?: '{}';
    }
}
