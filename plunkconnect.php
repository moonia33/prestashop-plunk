<?php

/**
 * Plunk Connect - connects PrestaShop to a self-hosted Plunk instance.
 * Replaces Newsman (newsmanapp / newsmancoupons).
 *
 * Compatible: PrestaShop 8.1 - 9.x, PHP >= 8.1, multistore.
 * No HTTP call is ever made in a shopper request: everything goes through
 * the outbox table and a cron worker.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

use PlunkConnect\Api\PlunkClient;
use PlunkConnect\Config;
use PlunkConnect\Mapper\LocaleMapper;
use PlunkConnect\Outbox\Outbox;
use PlunkConnect\Outbox\OutboxProcessor;
use PlunkConnect\Service\GdprService;
use PlunkConnect\Service\SubscriptionService;
use PlunkConnect\Service\TrackingService;

class Plunkconnect extends Module
{
    private const HOOKS = [
        'moduleRoutes',
        'actionNewsletterRegistrationAfter',
        'actionCustomerAccountAdd',
        'actionObjectCustomerUpdateBefore',
        'actionObjectCustomerUpdateAfter',
        'actionCartSave',
        'actionObjectCartUpdateAfter',
        'actionValidateOrder',
        'actionOrderStatusPostUpdate',
        'actionFrontControllerSetMedia',
        'actionUpdateQuantity',
        'actionObjectStockAvailableUpdateAfter',
        'actionDeleteGDPRCustomer',
        'actionExportGDPRData',
        'actionCronJob',
        'cronJob',
    ];

    /** @var bool|int remembers the newsletter flag before a customer update */
    private $customerNewsletterBefore = false;

    public function __construct()
    {
        $this->name = 'plunkconnect';
        $this->tab = 'advertising_marketing';
        $this->version = '1.0.0';
        $this->author = 'TV Parkas';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Plunk Connect');
        $this->description = $this->l(
            'Connects this shop to a self-hosted Plunk instance: newsletter sync (double opt-in), '
            . 'behavioural events, catalog feed and coupons.'
        );
        $this->confirmUninstall = $this->l('All queued Plunk events will be lost. Plunk-side data is not touched.');
        $this->ps_versions_compliancy = ['min' => '8.1.0', 'max' => '9.99.99'];
    }

    public function install(): bool
    {
        return parent::install()
            && $this->registerHook(self::HOOKS)
            && $this->runSql('install')
            && $this->installDefaults();
    }

    public function uninstall(): bool
    {
        foreach (Config::ALL_KEYS as $key) {
            Configuration::deleteByName($key);
        }

        return $this->runSql('uninstall') && parent::uninstall();
    }

    private function runSql(string $file): bool
    {
        $path = __DIR__ . '/sql/' . $file . '.php';
        if (!file_exists($path)) {
            return true;
        }

        return (bool) require $path;
    }

    private function installDefaults(): bool
    {
        Config::set(Config::WEBHOOK_SECRET, Config::generateSecret(32));
        Config::set(Config::FEED_TOKEN, Config::generateSecret(24));
        Config::set(Config::CRON_TOKEN, Config::generateSecret(24));
        Config::set(Config::LOCALE_FALLBACK, LocaleMapper::DEFAULT_FALLBACK);
        Config::set(Config::FEAT_SUBSCRIPTIONS, 1);
        Config::set(Config::FEAT_DOUBLE_OPTIN, 1);
        Config::set(Config::CONSENT_SOURCE, 'website_signup');
        Config::set(Config::DB_VERSION, $this->version);

        return true;
    }

    // ------------------------------------------------------------------
    // Hooks: friendly routes
    // ------------------------------------------------------------------

    public function hookModuleRoutes(): array
    {
        return [
            'plunkconnect-webhook' => [
                'rule' => 'webhook',
                'controller' => 'webhook',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
            'plunkconnect-cron' => [
                'rule' => 'cron',
                'controller' => 'cron',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
            'plunkconnect-feed' => [
                'rule' => 'feed/{type}',
                'controller' => 'feed',
                'keywords' => [
                    'type' => ['regexp' => 'products|categories', 'param' => 'type'],
                ],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
            'plunkconnect-products' => [
                'rule' => 'products',
                'controller' => 'products',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
            'plunkconnect-recommendations' => [
                'rule' => 'recommendations',
                'controller' => 'recommendations',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
            'plunkconnect-coupon' => [
                'rule' => 'coupon',
                'controller' => 'coupon',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
            'plunkconnect-restorecart' => [
                'rule' => 'restorecart',
                'controller' => 'restorecart',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Hooks: subscriptions
    // ------------------------------------------------------------------

    public function hookActionNewsletterRegistrationAfter(array $params): void
    {
        $email = strtolower(trim((string) ($params['email'] ?? '')));
        if (!Validate::isEmail($email)) {
            return;
        }
        $action = (string) ($params['action'] ?? '');
        if ('unsubscribed' === $action || 'delete' === $action) {
            SubscriptionService::unsubscribe($email);

            return;
        }
        SubscriptionService::signup($email);
        TrackingService::flushAnonymousViews($email, (int) $this->context->language->id);
    }

    public function hookActionCustomerAccountAdd(array $params): void
    {
        $customer = $params['newCustomer'] ?? $params['customer'] ?? null;
        if (!$customer instanceof Customer || !Validate::isEmail($customer->email)) {
            return;
        }
        TrackingService::customerRegistered($customer);
        if (!empty($customer->newsletter)) {
            SubscriptionService::signup(strtolower($customer->email), [], $customer);
        }
    }

    public function hookActionObjectCustomerUpdateBefore(array $params): void
    {
        $customer = $params['object'] ?? null;
        if ($customer instanceof Customer && $customer->id) {
            $existing = new Customer((int) $customer->id);
            $this->customerNewsletterBefore = Validate::isLoadedObject($existing)
                ? (int) $existing->newsletter
                : (int) $customer->newsletter;
        }
    }

    public function hookActionObjectCustomerUpdateAfter(array $params): void
    {
        $customer = $params['object'] ?? null;
        if (!$customer instanceof Customer || !Validate::isEmail($customer->email)) {
            return;
        }
        $now = (int) $customer->newsletter;
        if ($this->customerNewsletterBefore === $now) {
            return;
        }
        $email = strtolower((string) $customer->email);
        if (1 === $now) {
            SubscriptionService::signup($email, [], $customer);
            TrackingService::flushAnonymousViews($email, (int) $customer->id_lang);
        } else {
            SubscriptionService::unsubscribe($email, (int) $customer->id_lang);
        }
    }

    // ------------------------------------------------------------------
    // Hooks: behavioural events
    // ------------------------------------------------------------------

    public function hookActionFrontControllerSetMedia(array $params): void
    {
        try {
            TrackingService::onFrontControllerSetMedia();
        } catch (Throwable $e) {
            // Tracking must never break a page.
        }
    }

    public function hookActionCartSave(array $params): void
    {
        $cart = $params['cart'] ?? $this->context->cart;
        if ($cart instanceof Cart) {
            TrackingService::cartUpdated($cart);
        }
    }

    public function hookActionObjectCartUpdateAfter(array $params): void
    {
        $cart = $params['object'] ?? null;
        if ($cart instanceof Cart) {
            TrackingService::cartUpdated($cart);
        }
    }

    public function hookActionValidateOrder(array $params): void
    {
        try {
            TrackingService::orderPlaced($params);
        } catch (Throwable $e) {
            // Never block checkout.
        }
    }

    public function hookActionOrderStatusPostUpdate(array $params): void
    {
        try {
            TrackingService::orderStatusUpdated($params);
        } catch (Throwable $e) {
            // Never block BO actions.
        }
    }

    public function hookActionUpdateQuantity(array $params): void
    {
        $idProduct = (int) ($params['id_product'] ?? 0);
        $idAttr = (int) ($params['id_product_attribute'] ?? 0);
        $qty = (int) ($params['quantity'] ?? -1);
        if ($idProduct > 0 && $qty > 0) {
            TrackingService::backInStock($idProduct, $idAttr, $qty);
        }
    }

    public function hookActionObjectStockAvailableUpdateAfter(array $params): void
    {
        $stock = $params['object'] ?? null;
        if ($stock instanceof StockAvailable && (int) $stock->quantity > 0) {
            TrackingService::backInStock((int) $stock->id_product, (int) $stock->id_product_attribute, (int) $stock->quantity);
        }
    }

    // ------------------------------------------------------------------
    // Hooks: GDPR + cron
    // ------------------------------------------------------------------

    public function hookActionDeleteGDPRCustomer(array $params): void
    {
        $email = (string) ($params['email'] ?? '');
        if ('' === $email && isset($params['customer'])) {
            $customer = $params['customer'];
            $email = is_array($customer) ? (string) ($customer['email'] ?? '') : (string) ($customer->email ?? '');
        }
        GdprService::deleteCustomer(strtolower(trim($email)));
    }

    public function hookActionExportGDPRData(array $params): array
    {
        $email = (string) ($params['email'] ?? '');

        return GdprService::exportData(strtolower(trim($email)));
    }

    public function hookActionCronJob(array $params): void
    {
        // cronjobs module passes its job definition; run when the job
        // targets this module or when no target is given.
        $module = (string) ($params['module'] ?? '');
        if ('' !== $module && $module !== $this->name) {
            return;
        }
        $processor = new OutboxProcessor();
        $processor->process(100, 20);
        $processor->cleanup(1);
    }

    /**
     * Legacy hook name used by the cronjobs module - same behaviour.
     */
    public function hookCronJob(array $params = []): void
    {
        $this->hookActionCronJob($params);
    }

    // ------------------------------------------------------------------
    // Admin configuration
    // ------------------------------------------------------------------

    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('testPlunkConnection')) {
            $ping = (new PlunkClient())->ping();
            $output .= $ping['ok']
                ? $this->displayConfirmation($this->l('Connection OK:') . ' ' . $ping['message'])
                : $this->displayError($this->l('Connection failed:') . ' ' . $ping['message']);
        }
        if (Tools::isSubmit('regenPlunkToken')) {
            $key = (string) Tools::getValue('token_key');
            if (in_array($key, [Config::WEBHOOK_SECRET, Config::FEED_TOKEN, Config::CRON_TOKEN], true)) {
                Config::set($key, Config::generateSecret(32));
                $output .= $this->displayConfirmation($this->l('Token regenerated.'));
            }
        }
        if (Tools::isSubmit('plunkRetryDead')) {
            $n = Outbox::retryDead();
            $output .= $this->displayConfirmation(sprintf($this->l('%d dead rows requeued.'), $n));
        }
        if (Tools::isSubmit('plunkProcessNow')) {
            $stats = (new OutboxProcessor())->process(50, 20);
            $output .= $this->displayConfirmation(sprintf(
                $this->l('Outbox run: %d sent, %d retried, %d dead.'),
                $stats['sent'],
                $stats['retried'],
                $stats['dead']
            ));
        }
        if (Tools::isSubmit('submitPlunkConfig')) {
            $output .= $this->saveConfig();
        }

        return $output . $this->renderStatusPanel() . $this->renderForm();
    }

    private function saveConfig(): string
    {
        foreach ([Config::API_URL, Config::CONSENT_SOURCE, Config::SHOP_CODE,
            Config::CONSENT_COOKIE, Config::CONSENT_COOKIE_VALUE] as $key) {
            Config::set($key, trim((string) Tools::getValue($key)));
        }
        foreach ([Config::PUBLIC_KEY, Config::SECRET_KEY] as $key) {
            $value = trim((string) Tools::getValue($key));
            if ('' !== $value) {
                Config::set($key, $value);
            }
        }
        foreach ([Config::FEAT_SUBSCRIPTIONS, Config::FEAT_DOUBLE_OPTIN, Config::FEAT_VIEWS,
            Config::FEAT_CART, Config::FEAT_ORDERS, Config::FEAT_FEED,
            Config::FEAT_COUPONS, Config::FEAT_BACKINSTOCK, Config::FEAT_PK_CAPTURE] as $key) {
            Config::set($key, (int) (bool) Tools::getValue($key));
        }

        // Locale map: only allowed locales may be targets (never `ru`).
        $localeMap = [];
        foreach (LocaleMapper::shopLanguages() as $idLang => $iso) {
            $chosen = strtolower((string) Tools::getValue('PLUNK_LOCALE_' . $idLang));
            $localeMap[$idLang] = LocaleMapper::isAllowed($chosen) ? $chosen : $iso;
        }
        Config::set(Config::LOCALE_MAP, json_encode($localeMap));

        $fallback = strtolower((string) Tools::getValue(Config::LOCALE_FALLBACK));
        Config::set(Config::LOCALE_FALLBACK, LocaleMapper::isAllowed($fallback) ? $fallback : LocaleMapper::DEFAULT_FALLBACK);

        // Order state map: multiselect values arrive as arrays.
        $stateMap = [];
        foreach (['paid', 'shipped', 'delivered', 'cancelled', 'refunded'] as $event) {
            $selected = Tools::getValue('PLUNK_OS_' . $event);
            $stateMap[$event] = is_array($selected) ? array_map('intval', $selected) : [];
        }
        Config::set(Config::ORDERSTATE_MAP, json_encode($stateMap));

        return $this->displayConfirmation($this->l('Settings saved.'));
    }

    private function renderStatusPanel(): string
    {
        $counts = Outbox::counts();
        $errors = Outbox::lastErrors(10);
        $link = Context::getContext()->link;
        $webhookUrl = $link->getModuleLink($this->name, 'webhook');
        $feedUrl = $link->getModuleLink($this->name, 'feed', ['type' => 'products', 'lang' => 'lt']);
        $cronUrl = $link->getModuleLink($this->name, 'cron') . '&token=' . urlencode((string) Config::get(Config::CRON_TOKEN));

        $html = '<div class="panel"><h3><i class="icon-heartbeat"></i> '
            . $this->l('Outbox status') . '</h3><div class="row">'
            . '<div class="col-lg-8"><table class="table"><tbody><tr>'
            . '<td>' . $this->l('Pending') . '</td><td><b>' . $counts['pending'] . '</b></td>'
            . '<td>' . $this->l('Processing') . '</td><td><b>' . $counts['processing'] . '</b></td>'
            . '<td>' . $this->l('Sent') . '</td><td><b>' . $counts['sent'] . '</b></td>'
            . '<td>' . $this->l('Failed queue') . '</td><td><b>' . $counts['failed'] . '</b></td>'
            . '<td>' . $this->l('Dead') . '</td><td><b class="text-danger">' . $counts['dead'] . '</b></td>'
            . '</tr></tbody></table></div>'
            . '<div class="col-lg-4">'
            . '<form method="post" style="display:inline"><button class="btn btn-default" name="plunkProcessNow" value="1">'
            . $this->l('Process now') . '</button></form> '
            . '<form method="post" style="display:inline"><button class="btn btn-default" name="plunkRetryDead" value="1">'
            . $this->l('Retry dead') . '</button></form>'
            . '</div></div>';

        if ($errors) {
            $html .= '<table class="table"><thead><tr><th>#</th><th>' . $this->l('Endpoint')
                . '</th><th>' . $this->l('Attempts') . '</th><th>' . $this->l('Last error')
                . '</th><th>' . $this->l('Updated') . '</th></tr></thead><tbody>';
            foreach ($errors as $row) {
                $html .= '<tr><td>' . (int) $row['id_outbox'] . '</td>'
                    . '<td>' . htmlspecialchars((string) $row['endpoint']) . '</td>'
                    . '<td>' . (int) $row['attempts'] . '</td>'
                    . '<td>' . htmlspecialchars((string) $row['last_error']) . '</td>'
                    . '<td>' . htmlspecialchars((string) $row['date_upd']) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '<div class="alert alert-info"><b>' . $this->l('Endpoints') . '</b><br>'
            . 'Webhook: <code>' . htmlspecialchars($webhookUrl) . '</code><br>'
            . 'Products feed: <code>' . htmlspecialchars($feedUrl) . '</code><br>'
            . 'Cron URL: <code>' . htmlspecialchars($cronUrl) . '</code><br>'
            . 'CLI: <code>php bin/console plunkconnect:outbox:process</code></div>'
            . '</div>';

        return $html;
    }

    private function renderForm(): string
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->title = $this->displayName;
        $helper->submit_action = 'submitPlunkConfig';
        $helper->fields_value = $this->formValues();

        $forms = [
            ['form' => [
                'legend' => ['title' => $this->l('Plunk connection'), 'icon' => 'icon-plug'],
                'input' => [
                    ['type' => 'text', 'label' => $this->l('Plunk API URL'),
                        'name' => Config::API_URL, 'desc' => $this->l('e.g. https://api.example.com'), 'required' => true],
                    ['type' => 'password', 'label' => $this->l('Public key (pk_...)'),
                        'name' => Config::PUBLIC_KEY, 'desc' => $this->l('Leave empty to keep current value.')],
                    ['type' => 'password', 'label' => $this->l('Secret key (sk_...)'),
                        'name' => Config::SECRET_KEY, 'desc' => $this->l('Leave empty to keep current value.')],
                ],
                'submit' => ['title' => $this->l('Save')],
                'buttons' => [
                    ['title' => $this->l('Test connection'), 'name' => 'testPlunkConnection', 'type' => 'submit', 'class' => 'btn btn-default pull-right', 'icon' => 'process-icon-refresh'],
                ],
            ]],
            ['form' => [
                'legend' => ['title' => $this->l('Security tokens'), 'icon' => 'icon-key'],
                'input' => [
                    ['type' => 'free', 'label' => $this->l('Webhook secret'), 'name' => 'display_webhook'],
                    ['type' => 'free', 'label' => $this->l('Feed token'), 'name' => 'display_feed'],
                    ['type' => 'free', 'label' => $this->l('Cron token'), 'name' => 'display_cron'],
                ],
            ]],
            ['form' => [
                'legend' => ['title' => $this->l('Locales'), 'icon' => 'icon-globe'],
                'input' => $this->localeInputs(),
                'submit' => ['title' => $this->l('Save')],
            ]],
            ['form' => [
                'legend' => ['title' => $this->l('Order state mapping'), 'icon' => 'icon-truck'],
                'input' => $this->orderStateInputs(),
                'submit' => ['title' => $this->l('Save')],
            ]],
            ['form' => [
                'legend' => ['title' => $this->l('Features'), 'icon' => 'icon-cogs'],
                'input' => $this->featureInputs(),
                'submit' => ['title' => $this->l('Save')],
            ]],
            ['form' => [
                'legend' => ['title' => $this->l('Misc'), 'icon' => 'icon-wrench'],
                'input' => [
                    ['type' => 'text', 'label' => $this->l('consentSource'), 'name' => Config::CONSENT_SOURCE,
                        'desc' => $this->l('Value sent as data.consentSource, e.g. website_signup')],
                    ['type' => 'text', 'label' => $this->l('shopCode'), 'name' => Config::SHOP_CODE,
                        'desc' => $this->l('Short shop identifier sent with every event, e.g. bz')],
                    ['type' => 'text', 'label' => $this->l('Marketing consent cookie'), 'name' => Config::CONSENT_COOKIE,
                        'desc' => $this->l('Cookie set by your CMP that allows view tracking. Empty = always track.')],
                    ['type' => 'text', 'label' => $this->l('Consent cookie value'), 'name' => Config::CONSENT_COOKIE_VALUE,
                        'desc' => $this->l('Expected value (or one of comma-separated values), e.g. marketing')],
                ],
                'submit' => ['title' => $this->l('Save')],
            ]],
        ];

        return $helper->generateForm($forms);
    }

    private function localeInputs(): array
    {
        $inputs = [];
        $map = Config::getJson(Config::LOCALE_MAP);
        $options = [];
        foreach (LocaleMapper::ALLOWED as $loc) {
            $options[] = ['id' => $loc, 'name' => $loc];
        }
        foreach (LocaleMapper::shopLanguages() as $idLang => $iso) {
            $inputs[] = [
                'type' => 'select',
                'label' => sprintf($this->l('Language #%d (%s)'), $idLang, $iso),
                'name' => 'PLUNK_LOCALE_' . $idLang,
                'options' => ['query' => $options, 'id' => 'id', 'name' => 'name'],
            ];
        }
        $inputs[] = [
            'type' => 'select',
            'label' => $this->l('Fallback locale (e.g. for ru)'),
            'name' => Config::LOCALE_FALLBACK,
            'options' => ['query' => $options, 'id' => 'id', 'name' => 'name'],
            'desc' => $this->l('ru can never be a target; mapped languages falling outside lt/lv/et/en/pl use this locale.'),
        ];

        return $inputs;
    }

    private function orderStateInputs(): array
    {
        $states = OrderState::getOrderStates((int) Context::getContext()->language->id);
        $options = [];
        foreach ($states as $state) {
            $options[] = ['id' => (int) $state['id_order_state'], 'name' => $state['name']];
        }

        $inputs = [];
        foreach (['paid', 'shipped', 'delivered', 'cancelled', 'refunded'] as $event) {
            $inputs[] = [
                'type' => 'select',
                'multiple' => true,
                'size' => 6,
                'label' => sprintf($this->l('Order states meaning "%s"'), 'order.' . $event),
                'name' => 'PLUNK_OS_' . $event . '[]',
                'options' => ['query' => $options, 'id' => 'id', 'name' => 'name'],
            ];
        }

        return $inputs;
    }

    private function featureInputs(): array
    {
        $features = [
            [Config::FEAT_SUBSCRIPTIONS, 'Newsletter sync (PS <-> Plunk)'],
            [Config::FEAT_DOUBLE_OPTIN, 'Double opt-in (sign up via Plunk confirmation email)'],
            [Config::FEAT_VIEWS, 'View tracking (product/category)'],
            [Config::FEAT_CART, 'Cart events + abandoned cart link'],
            [Config::FEAT_ORDERS, 'Order events'],
            [Config::FEAT_FEED, 'Catalog feed + live products + recommendations'],
            [Config::FEAT_COUPONS, 'Coupon endpoint (replaces newsmancoupons)'],
            [Config::FEAT_BACKINSTOCK, 'Back-in-stock events (ps_emailalerts)'],
            [Config::FEAT_PK_CAPTURE, 'Identify visitors from Plunk email clicks (?pk_e=)'],
        ];

        $inputs = [];
        foreach ($features as [$key, $label]) {
            $inputs[] = [
                'type' => 'switch',
                'label' => $this->l($label),
                'name' => $key,
                'values' => [
                    ['id' => $key . '_on', 'value' => 1, 'label' => $this->l('Yes')],
                    ['id' => $key . '_off', 'value' => 0, 'label' => $this->l('No')],
                ],
            ];
        }

        return $inputs;
    }

    private function formValues(): array
    {
        $mask = fn (string $k) => $this->maskedTokenField($k);
        $values = [
            Config::API_URL => Config::get(Config::API_URL),
            Config::PUBLIC_KEY => '',
            Config::SECRET_KEY => '',
            Config::CONSENT_SOURCE => Config::get(Config::CONSENT_SOURCE),
            Config::SHOP_CODE => Config::get(Config::SHOP_CODE),
            Config::CONSENT_COOKIE => Config::get(Config::CONSENT_COOKIE),
            Config::CONSENT_COOKIE_VALUE => Config::get(Config::CONSENT_COOKIE_VALUE),
            Config::LOCALE_FALLBACK => LocaleMapper::fallback(),
            'display_webhook' => $mask(Config::WEBHOOK_SECRET),
            'display_feed' => $mask(Config::FEED_TOKEN),
            'display_cron' => $mask(Config::CRON_TOKEN),
        ];
        foreach ([Config::FEAT_SUBSCRIPTIONS, Config::FEAT_DOUBLE_OPTIN, Config::FEAT_VIEWS,
            Config::FEAT_CART, Config::FEAT_ORDERS, Config::FEAT_FEED,
            Config::FEAT_COUPONS, Config::FEAT_BACKINSTOCK, Config::FEAT_PK_CAPTURE] as $key) {
            $values[$key] = (int) Config::getBool($key);
        }
        foreach (LocaleMapper::shopLanguages() as $idLang => $iso) {
            $values['PLUNK_LOCALE_' . $idLang] = LocaleMapper::forLang($idLang);
        }
        $stateMap = Config::getJson(Config::ORDERSTATE_MAP);
        foreach (['paid', 'shipped', 'delivered', 'cancelled', 'refunded'] as $event) {
            $values['PLUNK_OS_' . $event . '[]'] = array_map('intval', (array) ($stateMap[$event] ?? []));
        }

        return $values;
    }

    private function maskedTokenField(string $key): string
    {
        $token = (string) Config::get($key);
        $masked = '' === $token ? $this->l('(not set)') : substr($token, 0, 6) . '***' . substr($token, -4);
        $regenUrl = AdminController::$currentIndex
            . '&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules')
            . '&regenPlunkToken=1&token_key=' . urlencode($key);

        return '<div class="form-control-static"><code>' . htmlspecialchars($masked) . '</code>'
            . ' <a class="btn btn-default btn-xs" href="' . htmlspecialchars($regenUrl) . '">'
            . $this->l('Regenerate') . '</a></div>';
    }
}
