<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * Replaces the Newsman integration: newsletter subscription sync
 * (double opt-in via Plunk workflows, inbound webhook, GDPR), with an
 * asynchronous outbox so Plunk outages can never slow down the shop.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

// The module has no external runtime dependencies; prefer composer autoload
// when present, fall back to a tiny PSR-4 loader for PlunkConnect\* classes.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'PlunkConnect\\';
        if (0 === strncmp($class, $prefix, strlen($prefix))) {
            $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });
}

use PlunkConnect\Config\LocaleMapper;
use PlunkConnect\Config\ModuleConfiguration;
use PlunkConnect\Gdpr\GdprService;
use PlunkConnect\Newsletter\NewsletterSyncService;
use PlunkConnect\Outbox\OutboxProcessor;
use PlunkConnect\Outbox\OutboxRepository;

class Plunkconnect extends Module
{
    /** @var array<int, int> Previous customer.newsletter values captured in actionObjectCustomerUpdateBefore. */
    private static $previousNewsletterFlags = [];

    /** @var ModuleConfiguration|null */
    private $configuration;

    public function __construct()
    {
        $this->name = 'plunkconnect';
        $this->tab = 'emailing';
        $this->version = '1.0.0';
        $this->author = 'TV Parkas';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.1.0', 'max' => '9.99.99'];
        $this->display = 'full';

        parent::__construct();

        $this->displayName = $this->trans('Plunk Connect', [], 'Modules.Plunkconnect.Admin');
        $this->description = $this->trans(
            'Syncs the shop with a self-hosted Plunk instance: newsletter subscriptions (double opt-in), inbound webhook, GDPR. All API calls go through a queued outbox.',
            [],
            'Modules.Plunkconnect.Admin'
        );
        $this->confirmUninstall = $this->trans('Are you sure? Queued messages and module configuration will be deleted.', [], 'Modules.Plunkconnect.Admin');
    }

    public function getConfiguration(): ModuleConfiguration
    {
        if (null === $this->configuration) {
            $this->configuration = new ModuleConfiguration();
        }

        return $this->configuration;
    }

    public function getOutboxProcessor(): OutboxProcessor
    {
        return new OutboxProcessor($this->getConfiguration(), null, null, static function (string $level, string $message): void {
            PrestaShopLogger::addLog($message, 'error' === $level ? 3 : 1, null, 'PlunkConnect', null, true);
        });
    }

    public function getSyncService(): NewsletterSyncService
    {
        return new NewsletterSyncService($this->getConfiguration());
    }

    /* ------------------------------------------------------------------ */
    /* Install / uninstall */
    /* ------------------------------------------------------------------ */

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        if (!$this->installDb()) {
            return false;
        }

        $hooks = [
            'actionNewsletterRegistrationBefore',
            'actionNewsletterRegistrationAfter',
            'actionCustomerAccountAdd',
            'actionObjectCustomerUpdateBefore',
            'actionObjectCustomerUpdateAfter',
            'actionDeleteGDPRCustomer',
            'actionExportGDPRData',
            'actionCronJob',
        ];

        if (!$this->registerHook($hooks)) {
            return false;
        }

        $config = $this->getConfiguration();
        $defaults = [
            ModuleConfiguration::FEAT_NEWSLETTER => '0',
            ModuleConfiguration::FEAT_DOUBLE_OPTIN => '1',
            ModuleConfiguration::FEAT_TRACKING => '0',
            ModuleConfiguration::FEAT_CART => '0',
            ModuleConfiguration::FEAT_ORDERS => '0',
            ModuleConfiguration::FEAT_FEED => '0',
            ModuleConfiguration::FEAT_COUPONS => '0',
            ModuleConfiguration::FEAT_BACKINSTOCK => '0',
            ModuleConfiguration::LOCALE_FALLBACK => 'lt',
            ModuleConfiguration::CONSENT_SOURCE => 'website_signup',
        ];
        foreach ($defaults as $key => $value) {
            if ('' === $config->get($key)) {
                $config->set($key, $value);
            }
        }
        $config->generateMissingTokens();

        return true;
    }

    public function uninstall(): bool
    {
        if (!$this->uninstallDb()) {
            return false;
        }

        // Remove every PLUNKCONNECT_* configuration value (all shops).
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'configuration`
            WHERE `name` LIKE \'PLUNKCONNECT\\_%\''
        );

        return parent::uninstall();
    }

    private function installDb(): bool
    {
        $sql = include __DIR__ . '/sql/install.php';
        foreach ($sql as $query) {
            $query = str_replace(['_DB_PREFIX_', '_MYSQL_ENGINE_'], [_DB_PREFIX_, _MYSQL_ENGINE_], $query);
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    private function uninstallDb(): bool
    {
        $sql = include __DIR__ . '/sql/uninstall.php';
        foreach ($sql as $query) {
            $query = str_replace('_DB_PREFIX_', _DB_PREFIX_, $query);
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Hooks – newsletter (stage 1) */
    /* ------------------------------------------------------------------ */

    /**
     * Fires for BOTH subscribe and unsubscribe attempts of the guest form.
     * The "After" hook only runs on subscribe, so an unsubscribe intent is
     * captured here and verified in a shutdown function (after the row has
     * actually been deleted by ps_emailsubscription).
     *
     * @param array<string, mixed> $params
     */
    public function hookActionNewsletterRegistrationBefore(array $params): void
    {
        try {
            if (!isset($params['action']) || 1 !== (int) $params['action']) { // NEWSLETTER_UNSUBSCRIPTION
                return;
            }
            $email = isset($params['email']) ? trim((string) $params['email']) : '';
            if (!Validate::isEmail($email)) {
                return;
            }

            $idShop = (int) $this->context->shop->id;

            // Only act if the email is currently subscribed in this shop.
            $exists = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'emailsubscription`
                WHERE `email` = \'' . pSQL($email) . '\' AND `id_shop` = ' . $idShop . ' AND `active` = 1'
            ) + (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer`
                WHERE `email` = \'' . pSQL($email) . '\' AND `id_shop` = ' . $idShop . ' AND `newsletter` = 1'
            );

            if (0 === $exists) {
                return;
            }

            $module = $this;
            register_shutdown_function(static function () use ($module, $email, $idShop): void {
                try {
                    $stillSubscribed = (int) Db::getInstance()->getValue(
                        'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'emailsubscription`
                        WHERE `email` = \'' . pSQL($email) . '\' AND `id_shop` = ' . (int) $idShop
                    ) + (int) Db::getInstance()->getValue(
                        'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer`
                        WHERE `email` = \'' . pSQL($email) . '\' AND `id_shop` = ' . (int) $idShop . ' AND `newsletter` = 1'
                    );

                    if (0 === $stillSubscribed) {
                        $module->getSyncService()->unsubscribed($email, $idShop);
                    }
                } catch (Throwable $e) {
                    PrestaShopLogger::addLog('PlunkConnect: guest unsubscribe check failed: ' . $e->getMessage(), 3, null, 'PlunkConnect', null, true);
                }
            });
        } catch (Throwable $e) {
            $this->logError('hookActionNewsletterRegistrationBefore', $e);
        }
    }

    /**
     * Fires after a successful guest subscription (and after verification
     * email confirmation). Does not fire for unsubscribes.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionNewsletterRegistrationAfter(array $params): void
    {
        try {
            if (!isset($params['action']) || 0 !== (int) $params['action']) { // NEWSLETTER_SUBSCRIPTION
                return;
            }
            if (!empty($params['error'])) {
                return;
            }
            $email = isset($params['email']) ? trim((string) $params['email']) : '';
            if (!Validate::isEmail($email)) {
                return;
            }

            $idShop = (int) $this->context->shop->id;
            $guestRow = Db::getInstance()->getRow(
                'SELECT `id_lang`, `ip_registration_newsletter`, `http_referer`
                FROM `' . _DB_PREFIX_ . 'emailsubscription`
                WHERE `email` = \'' . pSQL($email) . '\' AND `id_shop` = ' . $idShop
            );

            $idLang = $guestRow ? (int) $guestRow['id_lang'] : (int) $this->context->language->id;
            $this->getSyncService()->guestSubscribed($email, $idShop, $idLang, $this->langIso($idLang), $guestRow ?: null);
        } catch (Throwable $e) {
            $this->logError('hookActionNewsletterRegistrationAfter', $e);
        }
    }

    /**
     * Account creation. Sends a subscribe event when the newsletter box was
     * checked during registration.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionCustomerAccountAdd(array $params): void
    {
        try {
            $customer = $params['newCustomer'] ?? null;
            if (!$customer instanceof Customer || !(int) $customer->newsletter) {
                return;
            }

            $idShop = (int) ($customer->id_shop ?: $this->context->shop->id);
            $this->getSyncService()->customerSubscribed($customer, $idShop);
        } catch (Throwable $e) {
            $this->logError('hookActionCustomerAccountAdd', $e);
        }
    }

    /**
     * Captures the newsletter flag before a Customer update so the After
     * hook can detect a 0/1 flip.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionObjectCustomerUpdateBefore(array $params): void
    {
        try {
            $customer = $params['object'] ?? null;
            if (!$customer instanceof Customer || !(int) $customer->id) {
                return;
            }

            self::$previousNewsletterFlags[(int) $customer->id] = (int) Db::getInstance()->getValue(
                'SELECT `newsletter` FROM `' . _DB_PREFIX_ . 'customer`
                WHERE `id_customer` = ' . (int) $customer->id
            );
        } catch (Throwable $e) {
            $this->logError('hookActionObjectCustomerUpdateBefore', $e);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionObjectCustomerUpdateAfter(array $params): void
    {
        try {
            $customer = $params['object'] ?? null;
            if (!$customer instanceof Customer || !(int) $customer->id) {
                return;
            }

            $idCustomer = (int) $customer->id;
            if (!array_key_exists($idCustomer, self::$previousNewsletterFlags)) {
                return;
            }

            $before = self::$previousNewsletterFlags[$idCustomer];
            unset(self::$previousNewsletterFlags[$idCustomer]);
            $after = (int) $customer->newsletter;

            if ($before === $after) {
                return;
            }

            $idShop = (int) ($customer->id_shop ?: $this->context->shop->id);
            $sync = $this->getSyncService();

            if (1 === $after) {
                $sync->customerSubscribed($customer, $idShop);
            } else {
                $sync->unsubscribed((string) $customer->email, $idShop, (int) $customer->id_lang, $this->langIso((int) $customer->id_lang));
            }
        } catch (Throwable $e) {
            $this->logError('hookActionObjectCustomerUpdateAfter', $e);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Hooks – GDPR (psgdpr) */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionDeleteGDPRCustomer(array $params): void
    {
        try {
            $customer = isset($params['customer']) ? (array) $params['customer'] : $params;
            (new GdprService($this->getConfiguration(), $this->getSyncService()))
                ->onDeleteCustomer($customer, (int) $this->context->shop->id);
        } catch (Throwable $e) {
            $this->logError('hookActionDeleteGDPRCustomer', $e);
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return string JSON consumed by psgdpr
     */
    public function hookActionExportGDPRData(array $params)
    {
        try {
            $customer = isset($params['customer']) ? (array) $params['customer'] : $params;

            return (new GdprService($this->getConfiguration()))->onExportData($customer);
        } catch (Throwable $e) {
            $this->logError('hookActionExportGDPRData', $e);

            return '{}';
        }
    }

    /**
     * Fallback scheduler for the ps cronjobs module: drains a small batch.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionCronJob(array $params): void
    {
        try {
            $this->getOutboxProcessor()->process(50, 30);
        } catch (Throwable $e) {
            $this->logError('hookActionCronJob', $e);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Admin configuration */
    /* ------------------------------------------------------------------ */

    public function getContent(): string
    {
        $output = '';
        $config = $this->getConfiguration();
        $idShop = (int) $this->context->shop->id;
        $idShopGroup = (int) $this->context->shop->id_shop_group;

        if (Tools::isSubmit('plunkconnectRegenToken')) {
            $key = (string) Tools::getValue('token_key');
            if ($config->regenerateToken($key, $idShop, $idShopGroup)) {
                $output .= $this->displayConfirmation($this->trans('Token regenerated.', [], 'Modules.Plunkconnect.Admin'));
            }
        }

        if (Tools::isSubmit('plunkconnectRetryDead')) {
            $count = (new OutboxRepository())->retryDead($idShop);
            $output .= $this->displayConfirmation($this->trans('%d dead message(s) re-queued.', [$count], 'Modules.Plunkconnect.Admin'));
        }

        if (Tools::isSubmit('plunkconnectTestConnection')) {
            $output .= $this->runConnectionTest($idShop);
        }

        if (Tools::isSubmit('submitPlunkconnectSettings')) {
            $errors = $this->saveSettings($idShop, $idShopGroup);
            $output .= empty($errors)
                ? $this->displayConfirmation($this->trans('Settings saved.', [], 'Modules.Plunkconnect.Admin'))
                : $this->displayError(implode('<br>', $errors));
        }

        return $output . $this->renderStatusBlock($idShop) . $this->renderSettingsForm($idShop);
    }

    /**
     * @return string[] validation errors (empty on success)
     */
    private function saveSettings(int $idShop, int $idShopGroup): array
    {
        $config = $this->getConfiguration();
        $errors = [];

        $apiUrl = trim((string) Tools::getValue(ModuleConfiguration::API_URL));
        if ('' !== $apiUrl && !Validate::isAbsoluteUrl($apiUrl)) {
            $errors[] = $this->trans('API URL must be an absolute https:// URL.', [], 'Modules.Plunkconnect.Admin');
        }
        if ('' !== $apiUrl && 0 !== strpos($apiUrl, 'https://')) {
            $errors[] = $this->trans('API URL must use https://.', [], 'Modules.Plunkconnect.Admin');
        }

        $publicKey = trim((string) Tools::getValue(ModuleConfiguration::PUBLIC_KEY));
        $secretKey = trim((string) Tools::getValue(ModuleConfiguration::SECRET_KEY));
        foreach ([[ModuleConfiguration::PUBLIC_KEY, $publicKey, 'pk_'], [ModuleConfiguration::SECRET_KEY, $secretKey, 'sk_']] as $tuple) {
            if ('' !== $tuple[1] && !preg_match('/^' . $tuple[2] . '[A-Za-z0-9_\-]+$/', $tuple[1])) {
                $errors[] = $this->trans('%s must start with %s.', [$tuple[0], $tuple[2]], 'Modules.Plunkconnect.Admin');
            }
        }

        $fallback = strtolower(trim((string) Tools::getValue(ModuleConfiguration::LOCALE_FALLBACK)));
        if ('ru' === $fallback || !LocaleMapper::isAllowedLocale($fallback)) {
            $errors[] = $this->trans('Fallback locale is not allowed.', [], 'Modules.Plunkconnect.Admin');
        }

        $localeMap = [];
        foreach (Language::getLanguages(false, $idShop) as $lang) {
            $value = strtolower(trim((string) Tools::getValue('PLUNKCONNECT_LOCALE_' . (int) $lang['id_lang'])));
            if ('' !== $value) {
                if ('ru' === $value || !LocaleMapper::isAllowedLocale($value)) {
                    $errors[] = $this->trans('Locale for language #%d is not allowed.', [(int) $lang['id_lang']], 'Modules.Plunkconnect.Admin');
                    continue;
                }
                $localeMap[(int) $lang['id_lang']] = $value;
            }
        }

        if (!empty($errors)) {
            return $errors;
        }

        $config->set(ModuleConfiguration::API_URL, rtrim($apiUrl, '/'), $idShop, $idShopGroup);
        // Keys are only overwritten when the admin typed a new value —
        // the fields render empty (masked value shown as a note) so an
        // ordinary "Save" never blanks them.
        if ('' !== $publicKey) {
            $config->set(ModuleConfiguration::PUBLIC_KEY, $publicKey, $idShop, $idShopGroup);
        }
        if ('' !== $secretKey) {
            $config->set(ModuleConfiguration::SECRET_KEY, $secretKey, $idShop, $idShopGroup);
        }
        $config->set(ModuleConfiguration::CONSENT_SOURCE, preg_replace('/[^A-Za-z0-9_\-]/', '', (string) Tools::getValue(ModuleConfiguration::CONSENT_SOURCE)) ?: 'website_signup', $idShop, $idShopGroup);
        $config->set(ModuleConfiguration::SHOP_CODE, preg_replace('/[^A-Za-z0-9_\-]/', '', (string) Tools::getValue(ModuleConfiguration::SHOP_CODE)), $idShop, $idShopGroup);
        $config->set(ModuleConfiguration::LOCALE_FALLBACK, $fallback, $idShop, $idShopGroup);
        $config->set(ModuleConfiguration::LOCALE_MAP, json_encode($localeMap), $idShop, $idShopGroup);
        $config->set(ModuleConfiguration::CONSENT_COOKIE_NAME, trim((string) Tools::getValue(ModuleConfiguration::CONSENT_COOKIE_NAME)), $idShop, $idShopGroup);
        $config->set(ModuleConfiguration::CONSENT_COOKIE_VALUE, trim((string) Tools::getValue(ModuleConfiguration::CONSENT_COOKIE_VALUE)), $idShop, $idShopGroup);

        foreach ([
            ModuleConfiguration::FEAT_NEWSLETTER,
            ModuleConfiguration::FEAT_DOUBLE_OPTIN,
            ModuleConfiguration::FEAT_TRACKING,
            ModuleConfiguration::FEAT_CART,
            ModuleConfiguration::FEAT_ORDERS,
            ModuleConfiguration::FEAT_FEED,
            ModuleConfiguration::FEAT_COUPONS,
            ModuleConfiguration::FEAT_BACKINSTOCK,
        ] as $flag) {
            $config->set($flag, Tools::getValue($flag) ? '1' : '0', $idShop, $idShopGroup);
        }

        // Order state mapping (used from stage 2 onwards; stored already).
        $orderMap = [];
        foreach (['paid', 'shipped', 'delivered', 'cancelled', 'refunded'] as $state) {
            $ids = array_filter(array_map('intval', (array) Tools::getValue('PLUNKCONNECT_OS_' . $state, [])));
            if (!empty($ids)) {
                $orderMap[$state] = array_values($ids);
            }
        }
        $config->set(ModuleConfiguration::ORDERSTATE_MAP, json_encode($orderMap), $idShop, $idShopGroup);

        return [];
    }

    private function runConnectionTest(int $idShop): string
    {
        $config = $this->getConfiguration();
        if (!$config->isConfigured($idShop)) {
            return $this->displayError($this->trans('Configure API URL and keys first.', [], 'Modules.Plunkconnect.Admin'));
        }

        $response = $config->createClient($idShop)->testConnection();

        if ($response->isSuccess()) {
            return $this->displayConfirmation($this->trans('Connection OK (HTTP %d).', [$response->statusCode], 'Modules.Plunkconnect.Admin'));
        }

        $detail = $response->transportError ?: ('HTTP ' . $response->statusCode);

        return $this->displayError($this->trans('Connection failed: %s', [$detail], 'Modules.Plunkconnect.Admin'));
    }

    private function renderStatusBlock(int $idShop): string
    {
        $repo = new OutboxRepository();
        $counts = $repo->countsByStatus($idShop);
        $errors = $repo->lastErrors($idShop, 5);
        $config = $this->getConfiguration();

        $html = '<div class="panel"><h3><i class="icon-tasks"></i> ' . $this->trans('Outbox status', [], 'Modules.Plunkconnect.Admin') . '</h3>';
        $html .= '<div class="row">';
        foreach ([
            'pending' => 'warning',
            'processing' => 'info',
            'sent' => 'success',
            'failed' => 'danger',
            'dead' => 'danger',
        ] as $status => $color) {
            $html .= '<div class="col-lg-2"><div class="well text-center"><strong class="text-' . $color . '" style="font-size:2em">'
                . (int) $counts[$status] . '</strong><br>' . $status . '</div></div>';
        }
        $html .= '</div>';

        $html .= '<form method="post" style="display:inline">';
        $html .= '<button type="submit" name="plunkconnectRetryDead" class="btn btn-default">' . $this->trans('Re-queue dead messages', [], 'Modules.Plunkconnect.Admin') . '</button> ';
        $html .= '<button type="submit" name="plunkconnectTestConnection" class="btn btn-default">' . $this->trans('Test connection', [], 'Modules.Plunkconnect.Admin') . '</button>';
        $html .= '</form>';

        if (!empty($errors)) {
            $html .= '<h4>' . $this->trans('Recent errors', [], 'Modules.Plunkconnect.Admin') . '</h4><table class="table"><thead><tr><th>#</th><th>' . $this->trans('Endpoint', [], 'Modules.Plunkconnect.Admin') . '</th><th>' . $this->trans('Status', [], 'Modules.Plunkconnect.Admin') . '</th><th>' . $this->trans('Attempts', [], 'Modules.Plunkconnect.Admin') . '</th><th>' . $this->trans('Error', [], 'Modules.Plunkconnect.Admin') . '</th><th>' . $this->trans('Updated', [], 'Modules.Plunkconnect.Admin') . '</th></tr></thead><tbody>';
            foreach ($errors as $row) {
                $html .= '<tr><td>' . (int) $row['id_outbox'] . '</td><td>' . htmlspecialchars($row['endpoint']) . '</td><td>'
                    . htmlspecialchars($row['status']) . '</td><td>' . (int) $row['attempts'] . '</td><td>'
                    . htmlspecialchars($row['last_error']) . '</td><td>' . htmlspecialchars($row['date_upd']) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        // Endpoint URLs this shop must configure in Plunk / crontab.
        $baseUrl = $this->context->shop->getBaseURL(true);
        $cronToken = $config->get(ModuleConfiguration::CRON_TOKEN, $idShop);
        $html .= '<h4>' . $this->trans('Endpoints', [], 'Modules.Plunkconnect.Admin') . '</h4><table class="table">';
        $html .= '<tr><td>Webhook (Plunk → PS)</td><td><code>' . htmlspecialchars($baseUrl . 'module/plunkconnect/webhook') . '</code></td></tr>';
        $html .= '<tr><td>Cron (outbox worker)</td><td><code>' . htmlspecialchars($baseUrl . 'module/plunkconnect/cron?token=' . $cronToken) . '</code></td></tr>';
        $html .= '<tr><td>CLI worker</td><td><code>php bin/console plunkconnect:outbox:process</code></td></tr>';
        $html .= '</table></div>';

        return $html;
    }

    private function renderSettingsForm(int $idShop): string
    {
        $config = $this->getConfiguration();
        $fields = [];

        $fields[] = [
            'type' => 'text',
            'label' => $this->trans('Plunk API URL', [], 'Modules.Plunkconnect.Admin'),
            'name' => ModuleConfiguration::API_URL,
            'desc' => $this->trans('e.g. https://api.example.com — no trailing slash needed.', [], 'Modules.Plunkconnect.Admin'),
            'class' => 'fixed-width-xxl',
        ];
        $fields[] = [
            'type' => 'text',
            'label' => $this->trans('Public key (pk_…)', [], 'Modules.Plunkconnect.Admin'),
            'name' => ModuleConfiguration::PUBLIC_KEY,
            'desc' => $this->trans('Used for /v1/track. Current: %s', [$config->maskSecret($config->get(ModuleConfiguration::PUBLIC_KEY, $idShop))], 'Modules.Plunkconnect.Admin'),
            'class' => 'fixed-width-xxl',
            'autocomplete' => 'off',
        ];
        $fields[] = [
            'type' => 'text',
            'label' => $this->trans('Secret key (sk_…)', [], 'Modules.Plunkconnect.Admin'),
            'name' => ModuleConfiguration::SECRET_KEY,
            'desc' => $this->trans('Used for /contacts. Current: %s', [$config->maskSecret($config->get(ModuleConfiguration::SECRET_KEY, $idShop))], 'Modules.Plunkconnect.Admin'),
            'class' => 'fixed-width-xxl',
            'autocomplete' => 'off',
        ];
        $fields[] = [
            'type' => 'text',
            'label' => $this->trans('Consent source label', [], 'Modules.Plunkconnect.Admin'),
            'name' => ModuleConfiguration::CONSENT_SOURCE,
            'desc' => $this->trans('Sent as data.consentSource, e.g. website_signup.', [], 'Modules.Plunkconnect.Admin'),
        ];
        $fields[] = [
            'type' => 'text',
            'label' => $this->trans('Shop code', [], 'Modules.Plunkconnect.Admin'),
            'name' => ModuleConfiguration::SHOP_CODE,
            'desc' => $this->trans('Sent as data.shopCode to tell shops apart in Plunk, e.g. bz.', [], 'Modules.Plunkconnect.Admin'),
        ];

        // Locale mapping per language.
        $localeOptions = [];
        foreach (LocaleMapper::ALLOWED_LOCALES as $locale) {
            $localeOptions[] = ['id' => $locale, 'name' => $locale];
        }

        $savedMap = $config->getLocaleMap($idShop);
        foreach (Language::getLanguages(false, $idShop) as $lang) {
            $isoNote = 'ru' === strtolower((string) $lang['iso_code'])
                ? ' ' . $this->trans('(Russian is never sent; fallback applies)', [], 'Modules.Plunkconnect.Admin')
                : '';
            $fields[] = [
                'type' => 'select',
                'label' => sprintf($this->trans('Locale for %s (iso %s)', [], 'Modules.Plunkconnect.Admin'), $lang['name'], $lang['iso_code']) . $isoNote,
                'name' => 'PLUNKCONNECT_LOCALE_' . (int) $lang['id_lang'],
                'options' => ['query' => array_merge([['id' => '', 'name' => $this->trans('— auto (iso code) —', [], 'Modules.Plunkconnect.Admin')]], $localeOptions), 'id' => 'id', 'name' => 'name'],
            ];
        }

        $fields[] = [
            'type' => 'select',
            'label' => $this->trans('Fallback locale', [], 'Modules.Plunkconnect.Admin'),
            'name' => ModuleConfiguration::LOCALE_FALLBACK,
            'desc' => $this->trans('Used for unmapped languages and for Russian (ru is never sent).', [], 'Modules.Plunkconnect.Admin'),
            'options' => ['query' => $localeOptions, 'id' => 'id', 'name' => 'name'],
        ];

        // Feature switches.
        $featureFields = [
            ModuleConfiguration::FEAT_NEWSLETTER => $this->trans('Newsletter sync', [], 'Modules.Plunkconnect.Admin'),
            ModuleConfiguration::FEAT_DOUBLE_OPTIN => $this->trans('Double opt-in (Plunk confirms)', [], 'Modules.Plunkconnect.Admin'),
            ModuleConfiguration::FEAT_TRACKING => $this->trans('Behaviour tracking (stage 2)', [], 'Modules.Plunkconnect.Admin'),
            ModuleConfiguration::FEAT_CART => $this->trans('Cart events (stage 2)', [], 'Modules.Plunkconnect.Admin'),
            ModuleConfiguration::FEAT_ORDERS => $this->trans('Order events (stage 2)', [], 'Modules.Plunkconnect.Admin'),
            ModuleConfiguration::FEAT_FEED => $this->trans('Catalog feed (stage 3)', [], 'Modules.Plunkconnect.Admin'),
            ModuleConfiguration::FEAT_COUPONS => $this->trans('Coupons (stage 4)', [], 'Modules.Plunkconnect.Admin'),
            ModuleConfiguration::FEAT_BACKINSTOCK => $this->trans('Back-in-stock (stage 4)', [], 'Modules.Plunkconnect.Admin'),
        ];
        foreach ($featureFields as $key => $label) {
            $fields[] = [
                'type' => 'switch',
                'label' => $label,
                'name' => $key,
                'is_bool' => true,
                'values' => [
                    ['id' => $key . '_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Modules.Plunkconnect.Admin')],
                    ['id' => $key . '_off', 'value' => 0, 'label' => $this->trans('No', [], 'Modules.Plunkconnect.Admin')],
                ],
            ];
        }

        // Order state mapping multi-selects.
        $orderStates = OrderState::getOrderStates((int) $this->context->language->id);
        $osOptions = array_map(static function (array $os): array {
            return ['id' => (string) $os['id_order_state'], 'name' => $os['name']];
        }, $orderStates);

        $savedOsMap = json_decode($config->get(ModuleConfiguration::ORDERSTATE_MAP, $idShop) ?: '{}', true);
        foreach (['paid', 'shipped', 'delivered', 'cancelled', 'refunded'] as $state) {
            $fields[] = [
                'type' => 'select',
                'multiple' => true,
                'label' => sprintf($this->trans('Order states = %s', [], 'Modules.Plunkconnect.Admin'), $state),
                'name' => 'PLUNKCONNECT_OS_' . $state . '[]',
                'options' => ['query' => $osOptions, 'id' => 'id', 'name' => 'name'],
            ];
        }

        // CMP consent cookie (stage 2 tracking).
        $fields[] = [
            'type' => 'text',
            'label' => $this->trans('Marketing consent cookie name', [], 'Modules.Plunkconnect.Admin'),
            'name' => ModuleConfiguration::CONSENT_COOKIE_NAME,
            'desc' => $this->trans('Behaviour events are only tracked when this cookie matches the value below. Leave empty to track everyone.', [], 'Modules.Plunkconnect.Admin'),
        ];
        $fields[] = [
            'type' => 'text',
            'label' => $this->trans('Marketing consent cookie value', [], 'Modules.Plunkconnect.Admin'),
            'name' => ModuleConfiguration::CONSENT_COOKIE_VALUE,
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->title = $this->displayName;
        $helper->submit_action = 'submitPlunkconnectSettings';
        $helper->fields_value = $this->formValues($idShop);

        $form = [
            'form' => [
                'legend' => ['title' => $this->trans('Plunk connection', [], 'Modules.Plunkconnect.Admin'), 'icon' => 'icon-cogs'],
                'input' => $fields,
                'submit' => ['title' => $this->trans('Save', [], 'Modules.Plunkconnect.Admin')],
            ],
        ];

        $html = $helper->generateForm([$form]);

        // Token management block (separate small forms).
        $html .= '<div class="panel"><h3>' . $this->trans('Tokens', [], 'Modules.Plunkconnect.Admin') . '</h3><table class="table">';
        foreach ([
            ModuleConfiguration::WEBHOOK_SECRET => $this->trans('Webhook secret (Bearer for the inbound webhook)', [], 'Modules.Plunkconnect.Admin'),
            ModuleConfiguration::CRON_TOKEN => $this->trans('Cron token (for the cron front controller)', [], 'Modules.Plunkconnect.Admin'),
            ModuleConfiguration::FEED_TOKEN => $this->trans('Feed token (stage 3 feeds)', [], 'Modules.Plunkconnect.Admin'),
        ] as $key => $label) {
            $html .= '<tr><td>' . $label . '</td><td><code>' . htmlspecialchars($config->get($key, $idShop)) . '</code></td>'
                . '<td><form method="post" style="display:inline"><input type="hidden" name="token_key" value="' . $key . '">'
                . '<button type="submit" name="plunkconnectRegenToken" class="btn btn-default btn-xs">' . $this->trans('Regenerate', [], 'Modules.Plunkconnect.Admin') . '</button></form></td></tr>';
        }
        $html .= '</table></div>';

        return $html;
    }

    /**
     * @return array<string, mixed>
     */
    private function formValues(int $idShop): array
    {
        $config = $this->getConfiguration();
        $values = [
            ModuleConfiguration::API_URL => $config->get(ModuleConfiguration::API_URL, $idShop),
            ModuleConfiguration::PUBLIC_KEY => '',
            ModuleConfiguration::SECRET_KEY => '',
            ModuleConfiguration::CONSENT_SOURCE => $config->get(ModuleConfiguration::CONSENT_SOURCE, $idShop) ?: 'website_signup',
            ModuleConfiguration::SHOP_CODE => $config->get(ModuleConfiguration::SHOP_CODE, $idShop),
            ModuleConfiguration::LOCALE_FALLBACK => $config->getLocaleFallback($idShop),
            ModuleConfiguration::CONSENT_COOKIE_NAME => $config->get(ModuleConfiguration::CONSENT_COOKIE_NAME, $idShop),
            ModuleConfiguration::CONSENT_COOKIE_VALUE => $config->get(ModuleConfiguration::CONSENT_COOKIE_VALUE, $idShop),
        ];

        // Keys: leave fields empty (placeholder shows masked value) unless a
        // new value was POSTed — avoids re-saving masked values or blanking.
        if (Tools::isSubmit('submitPlunkconnectSettings')) {
            $values[ModuleConfiguration::PUBLIC_KEY] = (string) Tools::getValue(ModuleConfiguration::PUBLIC_KEY);
            $values[ModuleConfiguration::SECRET_KEY] = (string) Tools::getValue(ModuleConfiguration::SECRET_KEY);
        } else {
            // Show nothing sensitive; admin only fills when rotating keys.
            $values[ModuleConfiguration::PUBLIC_KEY] = '';
            $values[ModuleConfiguration::SECRET_KEY] = '';
        }

        $savedMap = $config->getLocaleMap($idShop);
        foreach (Language::getLanguages(false, $idShop) as $lang) {
            $values['PLUNKCONNECT_LOCALE_' . (int) $lang['id_lang']] = $savedMap[(int) $lang['id_lang']] ?? '';
        }

        foreach ([
            ModuleConfiguration::FEAT_NEWSLETTER,
            ModuleConfiguration::FEAT_DOUBLE_OPTIN,
            ModuleConfiguration::FEAT_TRACKING,
            ModuleConfiguration::FEAT_CART,
            ModuleConfiguration::FEAT_ORDERS,
            ModuleConfiguration::FEAT_FEED,
            ModuleConfiguration::FEAT_COUPONS,
            ModuleConfiguration::FEAT_BACKINSTOCK,
        ] as $flag) {
            $values[$flag] = (int) $config->getBool($flag, $idShop);
        }

        $savedOsMap = json_decode($config->get(ModuleConfiguration::ORDERSTATE_MAP, $idShop) ?: '{}', true);
        foreach (['paid', 'shipped', 'delivered', 'cancelled', 'refunded'] as $state) {
            $values['PLUNKCONNECT_OS_' . $state . '[]'] = $savedOsMap[$state] ?? [];
        }

        return $values;
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    private function langIso(int $idLang): string
    {
        $lang = new Language($idLang);

        return Validate::isLoadedObject($lang) ? (string) $lang->iso_code : '';
    }

    private function logError(string $where, Throwable $e): void
    {
        PrestaShopLogger::addLog(
            sprintf('PlunkConnect %s: %s', $where, $e->getMessage()),
            3,
            (int) $e->getCode() ?: null,
            'PlunkConnect',
            null,
            true
        );
    }
}
