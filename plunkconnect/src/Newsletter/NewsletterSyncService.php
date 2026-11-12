<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * Translates PrestaShop newsletter events into outbox messages.
 * All public methods are safe to call from hooks: they do at most one
 * indexed INSERT/UPDATE and never talk to the network.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

namespace PlunkConnect\Newsletter;

use PlunkConnect\Config\LocaleMapper;
use PlunkConnect\Config\ModuleConfiguration;
use PlunkConnect\Outbox\OutboxRepository;
use PlunkConnect\Util\EmailMask;

class NewsletterSyncService
{
    /** @var ModuleConfiguration */
    private $config;

    /** @var OutboxRepository */
    private $outbox;

    public function __construct(ModuleConfiguration $config, ?OutboxRepository $outbox = null)
    {
        $this->config = $config;
        $this->outbox = $outbox ?: new OutboxRepository();
    }

    /**
     * Guest subscribed via the ps_emailsubscription form (or confirmed the
     * verification email).
     *
     * @param int $idLang Language the form was submitted in
     * @param string $isoCode Its iso code (for locale mapping)
     * @param array<string, mixed>|null $guestRow ps_emailsubscription row when available
     */
    public function guestSubscribed(string $email, int $idShop, int $idLang, string $isoCode = '', ?array $guestRow = null): void
    {
        if (!$this->isEnabled($idShop, $email)) {
            return;
        }

        $consentIp = $guestRow['ip_registration_newsletter'] ?? \Tools::getRemoteAddr();
        $consentReferer = isset($guestRow['http_referer']) ? (string) $guestRow['http_referer'] : '';

        $data = NewsletterPayload::consentData(
            $this->localeMapper($idShop)->localeForLang($idLang, $isoCode),
            $this->config->get(ModuleConfiguration::CONSENT_SOURCE, $idShop) ?: 'website_signup',
            $this->config->get(ModuleConfiguration::SHOP_CODE, $idShop),
            is_string($consentIp) ? $consentIp : '',
            $consentReferer
        );

        $payload = $this->config->getBool(ModuleConfiguration::FEAT_DOUBLE_OPTIN, $idShop)
            ? NewsletterPayload::signupRequested($email, $data)
            : NewsletterPayload::subscribed($email, $data);

        $this->enqueueTrack($idShop, $payload, $email);
    }

    /**
     * Registered customer subscribed (account creation with newsletter
     * checked, checkbox in account, checkout consent, …).
     */
    public function customerSubscribed(\Customer $customer, int $idShop): void
    {
        $email = (string) $customer->email;
        if (!$this->isEnabled($idShop, $email)) {
            return;
        }

        $isoCode = $this->langIso((int) $customer->id_lang);
        $data = NewsletterPayload::consentData(
            $this->localeMapper($idShop)->localeForLang((int) $customer->id_lang, $isoCode),
            $this->config->get(ModuleConfiguration::CONSENT_SOURCE, $idShop) ?: 'website_signup',
            $this->config->get(ModuleConfiguration::SHOP_CODE, $idShop),
            (string) $customer->ip_registration_newsletter,
            '',
            [
                'firstName' => (string) $customer->firstname,
                'lastName' => (string) $customer->lastname,
                'customerId' => (int) $customer->id,
            ]
        );

        $payload = $this->config->getBool(ModuleConfiguration::FEAT_DOUBLE_OPTIN, $idShop)
            ? NewsletterPayload::signupRequested($email, $data)
            : NewsletterPayload::subscribed($email, $data);

        $this->enqueueTrack($idShop, $payload, $email);
    }

    /**
     * Any unsubscribe that happened inside PrestaShop (guest form, account
     * checkbox off). Sends subscribed=false explicitly.
     */
    public function unsubscribed(string $email, int $idShop, int $idLang = 0, string $isoCode = ''): void
    {
        if (!$this->isEnabled($idShop, $email)) {
            return;
        }

        $data = NewsletterPayload::consentData(
            $this->localeMapper($idShop)->localeForLang($idLang, $isoCode),
            'website_unsubscribe',
            $this->config->get(ModuleConfiguration::SHOP_CODE, $idShop)
        );

        $this->enqueueTrack($idShop, NewsletterPayload::unsubscribed($email, $data), $email);
    }

    /**
     * GDPR erasure: contact delete goes through the outbox like everything
     * else — never a synchronous API call inside a hook.
     */
    public function scheduleContactDeletion(string $email, int $idShop): void
    {
        if (!\Validate::isEmail($email)) {
            return;
        }

        $this->outbox->enqueue(
            $idShop,
            OutboxRepository::ENDPOINT_DELETE,
            ['email' => $email],
            'delete:' . $idShop . ':' . hash('sha256', strtolower($email))
        );
    }

    private function isEnabled(int $idShop, string $email): bool
    {
        if (!$this->config->isFeatureEnabled(ModuleConfiguration::FEAT_NEWSLETTER, $idShop)) {
            return false;
        }
        if (!\Validate::isEmail($email)) {
            return false;
        }

        return true;
    }

    private function localeMapper(int $idShop): LocaleMapper
    {
        return new LocaleMapper(
            $this->config->getLocaleMap($idShop),
            $this->config->getLocaleFallback($idShop)
        );
    }

    private function langIso(int $idLang): string
    {
        $lang = new \Language($idLang);

        return \Validate::isLoadedObject($lang) ? (string) $lang->iso_code : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function enqueueTrack(int $idShop, array $payload, string $email): void
    {
        $this->outbox->enqueue(
            $idShop,
            OutboxRepository::ENDPOINT_TRACK,
            $payload,
            'newsletter:' . $idShop . ':' . hash('sha256', strtolower($email))
        );

        \PrestaShopLogger::addLog(
            'PlunkConnect: queued ' . (string) ($payload['event'] ?? '?') . ' for ' . EmailMask::mask($email),
            1,
            null,
            'PlunkConnect',
            null,
            true
        );
    }
}
