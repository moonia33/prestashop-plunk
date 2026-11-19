<?php

/**
 * Form protection: Cloudflare Turnstile (server-side Siteverify) and limits
 * on the PrestaShop `contactform` (per IP / per email) and the
 * `ps_emailsubscription` newsletter form (per IP; FEAT_NEWSLETTER_TURNSTILE).
 * Site key, secret and hostnames are per shop, so each shop / client keeps
 * its own widget. Off by default (FEAT_CONTACT_TURNSTILE).
 */

namespace PlunkConnect\Service;

use PlunkConnect\Config;

final class ContactGuardService
{
    public const ACTION = 'contact_form';
    public const ACTION_NEWSLETTER = 'newsletter';
    public const TOKEN_FIELD = 'cf-turnstile-response';

    private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const MAX_PER_IP_10MIN = 3;
    private const MAX_PER_EMAIL_DAY = 5;
    private const MAX_NEWSLETTER_PER_IP_HOUR = 3;

    private const MESSAGES = [
        'verify' => [
            'lt' => 'Nepavyko patvirtinti, kad esate ne robotas. Pabandykite dar kartą.',
            'lv' => 'Neizdevās apstiprināt, ka neesat robots. Lūdzu, mēģiniet vēlreiz.',
            'et' => 'Ei õnnestunud kinnitada, et te pole robot. Palun proovige uuesti.',
            'pl' => 'Nie udało się potwierdzić, że nie jesteś robotem. Spróbuj ponownie.',
            'ru' => 'Не удалось подтвердить, что вы не робот. Попробуйте ещё раз.',
            'en' => 'We could not verify that you are not a robot. Please try again.',
        ],
        'limit' => [
            'lt' => 'Išsiuntėte per daug žinučių. Pabandykite vėliau.',
            'lv' => 'Jūs nosūtījāt pārāk daudz ziņojumu. Lūdzu, mēģiniet vēlāk.',
            'et' => 'Saatsite liiga palju sõnumeid. Palun proovige hiljem.',
            'pl' => 'Wysłano zbyt wiele wiadomości. Spróbuj później.',
            'ru' => 'Вы отправили слишком много сообщений. Попробуйте позже.',
            'en' => 'You have sent too many messages. Please try again later.',
        ],
    ];

    public static function isActive(): bool
    {
        return Config::getBool(Config::FEAT_CONTACT_TURNSTILE) && self::hasKeys();
    }

    public static function isNewsletterActive(): bool
    {
        return Config::getBool(Config::FEAT_NEWSLETTER_TURNSTILE) && self::hasKeys();
    }

    private static function hasKeys(): bool
    {
        return '' !== (string) Config::get(Config::TURNSTILE_SITE_KEY)
            && '' !== (string) Config::get(Config::TURNSTILE_SECRET);
    }

    /**
     * `displayNewsletterRegistration` output: the widget inside the newsletter form.
     */
    public static function newsletterWidgetHtml(): string
    {
        if (!self::isNewsletterActive()) {
            return '';
        }

        return self::renderWidget((string) Config::get(Config::TURNSTILE_SITE_KEY), self::ACTION_NEWSLETTER);
    }

    /**
     * `displayGDPRConsent` output: the widget, only inside the contactform form.
     */
    public static function widgetHtml(int $idModule): string
    {
        if (!self::isActive() || $idModule <= 0 || $idModule !== (int) \Module::getModuleIdByName('contactform')) {
            return '';
        }

        return self::renderWidget((string) Config::get(Config::TURNSTILE_SITE_KEY));
    }

    public static function renderWidget(string $siteKey, string $action = self::ACTION): string
    {
        static $scriptSent = false;
        if ('' === $siteKey) {
            return '';
        }
        $script = $scriptSent ? '' : '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
        $scriptSent = true;

        return $script
            . '<div class="cf-turnstile" data-sitekey="' . htmlspecialchars($siteKey, ENT_QUOTES, 'UTF-8')
            . '" data-action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" data-language="auto"></div>';
    }

    /**
     * `actionFrontControllerInitAfter`: runs before contactform handles the
     * POST. A rejected submission has `submitMessage` removed so contactform
     * stores and mails nothing; the visitor sees the error and the form keeps
     * the typed values.
     */
    public static function guard(\FrontController $controller): void
    {
        if (\Tools::isSubmit('submitNewsletter') && self::isNewsletterActive()) {
            self::guardNewsletter($controller);
        }
        if ('contact' !== ($controller->php_self ?? '') || !\Tools::isSubmit('submitMessage') || !self::isActive()) {
            return;
        }

        $ip = (string) \Tools::getRemoteAddr();
        $email = strtolower(trim((string) \Tools::getValue('from')));
        $reason = null;
        if (self::overLimit($ip, $email)) {
            $reason = 'limit';
        } elseif (!self::verifyToken((string) \Tools::getValue(self::TOKEN_FIELD), $ip)) {
            $reason = 'verify';
        }
        if (null === $reason) {
            return;
        }

        unset($_POST['submitMessage'], $_GET['submitMessage']);
        $iso = (string) (\Context::getContext()->language->iso_code ?? 'en');
        $controller->errors[] = self::MESSAGES[$reason][$iso] ?? self::MESSAGES[$reason]['en'];
    }

    /**
     * Runs before ps_emailsubscription reads the POST (its widget renders
     * later): a rejected subscribe has `submitNewsletter` removed, so nothing
     * is stored. Unsubscribe requests (`action=1`) are left alone.
     */
    private static function guardNewsletter(\FrontController $controller): void
    {
        if ('1' === (string) \Tools::getValue('action')) {
            return;
        }
        $ip = (string) \Tools::getRemoteAddr();
        if (!self::newsletterOverLimit($ip)
            && self::verifyToken((string) \Tools::getValue(self::TOKEN_FIELD), $ip, self::ACTION_NEWSLETTER)) {
            return;
        }
        unset($_POST['submitNewsletter'], $_GET['submitNewsletter']);
        $iso = (string) (\Context::getContext()->language->iso_code ?? 'en');
        $controller->errors[] = self::MESSAGES['verify'][$iso] ?? self::MESSAGES['verify']['en'];
    }

    private static function newsletterOverLimit(string $ip): bool
    {
        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $db = \Db::getInstance();
        if (!$db->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'emailsubscription\'')) {
            return false;
        }

        return (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'emailsubscription`
             WHERE `ip_registration_newsletter` = \'' . pSQL($ip) . '\'
               AND `id_shop` = ' . (int) (\Context::getContext()->shop->id ?? 0) . '
               AND `newsletter_date_add` > \'' . pSQL(date('Y-m-d H:i:s', time() - 3600)) . '\''
        ) >= self::MAX_NEWSLETTER_PER_IP_HOUR;
    }

    public static function verifyToken(string $token, string $ip, string $action = self::ACTION): bool
    {
        if ('' === $token || strlen($token) > 2048) {
            return false;
        }
        $fields = ['secret' => (string) Config::get(Config::TURNSTILE_SECRET), 'response' => $token];
        if (false !== filter_var($ip, FILTER_VALIDATE_IP)) {
            $fields['remoteip'] = $ip;
        }

        $ch = curl_init(self::SITEVERIFY_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $response = is_string($raw) ? json_decode($raw, true) : null;

        // Siteverify unreachable or malformed: fail closed.
        return is_array($response) && self::isValidResponse($response, self::hostnames(), $action);
    }

    /**
     * @param array<string, mixed> $response Siteverify JSON
     * @param string[] $hostnames allowed frontend hostnames
     */
    public static function isValidResponse(array $response, array $hostnames, string $action = self::ACTION): bool
    {
        if (true !== ($response['success'] ?? null) || $action !== ($response['action'] ?? null)) {
            return false;
        }
        $hostname = strtolower((string) ($response['hostname'] ?? ''));

        return '' !== $hostname && in_array($hostname, $hostnames, true);
    }

    /**
     * Configured allowlist, or the shop's own domains when empty.
     *
     * @return string[]
     */
    public static function hostnames(): array
    {
        $configured = self::parseHostnames((string) Config::get(Config::TURNSTILE_HOSTNAMES));
        if ($configured) {
            return $configured;
        }

        $shop = \Context::getContext()->shop ?? null;

        return self::parseHostnames((string) ($shop->domain ?? '') . ',' . (string) ($shop->domain_ssl ?? ''));
    }

    /**
     * @return string[]
     */
    public static function parseHostnames(string $value): array
    {
        $hosts = [];
        foreach (preg_split('/[\s,]+/', strtolower($value)) ?: [] as $host) {
            $host = preg_replace('#^https?://#', '', $host) ?? '';
            $host = explode('/', $host)[0];
            $host = explode(':', $host)[0];
            if ('' !== $host && preg_match('/^[a-z0-9.-]+$/', $host)) {
                $hosts[$host] = true;
            }
        }

        return array_keys($hosts);
    }

    private static function overLimit(string $ip, string $email): bool
    {
        $db = \Db::getInstance();
        $idShop = (int) (\Context::getContext()->shop->id ?? 0);
        $long = ip2long($ip);
        if (false !== $long) {
            $recent = (int) $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer_message` cm
                 INNER JOIN `' . _DB_PREFIX_ . 'customer_thread` ct ON ct.`id_customer_thread` = cm.`id_customer_thread`
                 WHERE cm.`ip_address` = \'' . pSQL((string) $long) . '\' AND ct.`id_shop` = ' . $idShop . '
                   AND cm.`date_add` > \'' . pSQL(date('Y-m-d H:i:s', time() - 600)) . '\''
            );
            if ($recent >= self::MAX_PER_IP_10MIN) {
                return true;
            }
        }
        if ('' !== $email && \Validate::isEmail($email)) {
            $daily = (int) $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer_message` cm
                 INNER JOIN `' . _DB_PREFIX_ . 'customer_thread` ct ON ct.`id_customer_thread` = cm.`id_customer_thread`
                 WHERE ct.`email` = \'' . pSQL($email) . '\' AND ct.`id_shop` = ' . $idShop . ' AND cm.`id_employee` = 0
                   AND cm.`date_add` > \'' . pSQL(date('Y-m-d H:i:s', time() - 86400)) . '\''
            );
            if ($daily >= self::MAX_PER_EMAIL_DAY) {
                return true;
            }
        }

        return false;
    }
}
