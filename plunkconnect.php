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
use PlunkConnect\Service\ContactGuardService;
use PlunkConnect\Service\FormsService;
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
        'displayHeader',
        'actionFrontControllerInitAfter',
        'displayGDPRConsent',
        'displayNewsletterRegistration',
        'actionUpdateQuantity',
        'actionObjectStockAvailableUpdateAfter',
        'actionProductAdd',
        'actionProductUpdate',
        'actionProductDelete',
        'actionObjectProductDeleteBefore',
        'actionObjectCombinationAddAfter',
        'actionObjectCombinationUpdateAfter',
        'actionObjectCombinationDeleteAfter',
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceUpdateAfter',
        'actionObjectSpecificPriceDeleteAfter',
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
        $this->version = '1.8.0';
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
        $this->enableServicesYaml();

        return parent::install()
            && $this->registerHook(self::HOOKS)
            && $this->runSql('install')
            && $this->installDefaults();
    }

    /**
     * The Symfony container compiles module commands from
     * config/services.yml BEFORE the module autoloader is available -
     * classes resolve only through modules/<name>/vendor/autoload.php.
     * Ship the file only when vendor exists so BO never fatals on
     * "Class PlunkConnect\Command\* cannot be found".
     */
    public function enableServicesYaml(): void
    {
        $src = __DIR__ . '/config/services.yml.dist';
        $dst = __DIR__ . '/config/services.yml';
        if (file_exists($src) && file_exists(__DIR__ . '/vendor/autoload.php')
            && (!file_exists($dst) || md5_file($src) !== md5_file($dst))) {
            copy($src, $dst);
        }
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
        Config::set(Config::COUPON_SECRET, Config::generateSecret(32));
        Config::set(Config::COUPON_RULES, '{}');
        Config::set(Config::LOCALE_FALLBACK, LocaleMapper::DEFAULT_FALLBACK);
        Config::set(Config::FEAT_SUBSCRIPTIONS, 1);
        Config::set(Config::FEAT_DOUBLE_OPTIN, 1);
        Config::set(Config::FEAT_CATALOG_SYNC, 0);
        Config::set(Config::FEAT_FORMS, 0);
        Config::set(Config::FEAT_CONTACT_TURNSTILE, 0);
        Config::set(Config::FEAT_NEWSLETTER_TURNSTILE, 0);
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

    public function hookDisplayHeader(array $params): string
    {
        try {
            return FormsService::headerHtml();
        } catch (Throwable $e) {
            // Forms must never break a page.
            return '';
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

    public function hookActionFrontControllerInitAfter(array $params): void
    {
        $controller = $params['controller'] ?? null;
        if (!$controller instanceof FrontController) {
            return;
        }
        try {
            ContactGuardService::guard($controller);
        } catch (Throwable $e) {
            // Never break the contact page; contactform's own checks still apply.
            self::logHookError('actionFrontControllerInitAfter', $e);
        }
    }

    public function hookDisplayGDPRConsent(array $params): string
    {
        try {
            return ContactGuardService::widgetHtml((int) ($params['id_module'] ?? 0));
        } catch (Throwable $e) {
            return '';
        }
    }

    public function hookDisplayNewsletterRegistration(array $params): string
    {
        try {
            return ContactGuardService::newsletterWidgetHtml();
        } catch (Throwable $e) {
            return '';
        }
    }

    public function hookActionValidateOrder(array $params): void
    {
        try {
            TrackingService::orderPlaced($params);
        } catch (Throwable $e) {
            // Never block checkout.
            self::logHookError('actionValidateOrder', $e);
        }
    }

    public function hookActionOrderStatusPostUpdate(array $params): void
    {
        try {
            TrackingService::orderStatusUpdated($params);
        } catch (Throwable $e) {
            // Never block BO actions.
            self::logHookError('actionOrderStatusPostUpdate', $e);
        }
    }

    private static function logHookError(string $hook, Throwable $e): void
    {
        try {
            PrestaShopLogger::addLog(
                'plunkconnect ' . $hook . ': ' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 500),
                3,
                null,
                'plunkconnect'
            );
        } catch (Throwable $ignored) {
        }
    }

    public function hookActionUpdateQuantity(array $params): void
    {
        $idProduct = (int) ($params['id_product'] ?? 0);
        $idAttr = (int) ($params['id_product_attribute'] ?? 0);
        $qty = (int) ($params['quantity'] ?? -1);
        if ($idProduct > 0) {
            $this->catalogPatchProduct($idProduct, $idAttr);
        }
        if ($idProduct > 0 && $qty > 0) {
            TrackingService::backInStock($idProduct, $idAttr, $qty);
        }
    }

    public function hookActionObjectStockAvailableUpdateAfter(array $params): void
    {
        $stock = $params['object'] ?? null;
        if ($stock instanceof StockAvailable) {
            $this->catalogPatchProduct((int) $stock->id_product, (int) $stock->id_product_attribute);
            if ((int) $stock->quantity > 0) {
                TrackingService::backInStock((int) $stock->id_product, (int) $stock->id_product_attribute, (int) $stock->quantity);
            }
        }
    }

    // ------------------------------------------------------------------
    // Hooks: catalog sync -> Plunk (spec 5.4). Hooks only enqueue outbox
    // rows - never call the Plunk API from a back-office request.
    // ------------------------------------------------------------------

    public function hookActionProductAdd(array $params): void
    {
        $this->catalogUpsertProduct((int) ($params['product']->id ?? $params['id_product'] ?? 0));
    }

    public function hookActionProductUpdate(array $params): void
    {
        $this->catalogUpsertProduct((int) ($params['product']->id ?? $params['id_product'] ?? 0));
    }

    public function hookActionObjectCombinationAddAfter(array $params): void
    {
        $this->catalogUpsertProduct($this->combinationProductId($params));
    }

    public function hookActionObjectCombinationUpdateAfter(array $params): void
    {
        $this->catalogUpsertProduct($this->combinationProductId($params));
    }

    public function hookActionObjectCombinationDeleteAfter(array $params): void
    {
        if (!Config::getBool(Config::FEAT_CATALOG_SYNC)) {
            return;
        }
        $combo = $params['object'] ?? null;
        $idProduct = (int) ($combo->id_product ?? 0);
        $idAttr = (int) ($combo->id ?? 0);
        if ($idProduct > 0 && $idAttr > 0) {
            $idShop = (int) Context::getContext()->shop->id;
            $plunkId = PlunkConnect\Catalog\CatalogIds::forUnit($idProduct, $idAttr, $idShop);
            Outbox::enqueue('catalog.delete', ['ids' => [$plunkId], 'product' => $idProduct], 'catalog.delete:' . $plunkId);
            PlunkConnect\Catalog\CatalogIds::forget($idShop, $idProduct, $idAttr);
        }
    }

    /** Collect Plunk ids (incl. combinations) before the product rows vanish. */
    public function hookActionObjectProductDeleteBefore(array $params): void
    {
        $product = $params['object'] ?? null;
        $idProduct = (int) ($product->id ?? 0);
        if ($idProduct > 0) {
            $idShop = (int) Context::getContext()->shop->id;
            $this->deletingProductIds[$idProduct] = array_values(array_unique(array_merge(
                PlunkConnect\Catalog\CatalogIds::storedForProduct($idProduct, $idShop),
                array_values(PlunkConnect\Catalog\CatalogIds::forProducts([$idProduct], $idShop)[$idProduct] ?? [])
            )));
        }
    }

    public function hookActionProductDelete(array $params): void
    {
        if (!Config::getBool(Config::FEAT_CATALOG_SYNC)) {
            return;
        }
        $idProduct = (int) ($params['product']->id ?? $params['id_product'] ?? 0);
        $idShop = (int) Context::getContext()->shop->id;
        $ids = $this->deletingProductIds[$idProduct] ?? null;
        if (null === $ids) {
            // Product rows are gone: whatever was sent to Plunk, plus the parent fallback.
            $ids = $idProduct > 0 ? array_values(array_unique(array_merge(
                PlunkConnect\Catalog\CatalogIds::storedForProduct($idProduct, $idShop),
                [PlunkConnect\Catalog\CatalogIds::fallback($idProduct)]
            ))) : [];
        }
        if ($ids) {
            Outbox::enqueue('catalog.delete', ['ids' => $ids, 'product' => $idProduct], 'catalog.delete:p' . $idProduct);
            PlunkConnect\Catalog\CatalogIds::forget($idShop, $idProduct);
        }
    }

    public function hookActionObjectSpecificPriceAddAfter(array $params): void
    {
        $this->catalogPatchProduct($this->specificPriceProductId($params), 0);
    }

    public function hookActionObjectSpecificPriceUpdateAfter(array $params): void
    {
        $this->catalogPatchProduct($this->specificPriceProductId($params), 0);
    }

    public function hookActionObjectSpecificPriceDeleteAfter(array $params): void
    {
        $this->catalogPatchProduct($this->specificPriceProductId($params), 0);
    }

    private function combinationProductId(array $params): int
    {
        $object = $params['object'] ?? null;

        return (int) ($object->id_product ?? 0);
    }

    private function specificPriceProductId(array $params): int
    {
        $object = $params['object'] ?? null;

        return (int) ($object->id_product ?? 0);
    }

    private function catalogUpsertProduct(int $idProduct): void
    {
        if ($idProduct <= 0 || !Config::getBool(Config::FEAT_CATALOG_SYNC)) {
            return;
        }
        Outbox::enqueue('catalog.upsert', ['ids' => [$idProduct]], 'catalog.upsert:p' . $idProduct);
    }

    private function catalogPatchProduct(int $idProduct, int $idAttr): void
    {
        if ($idProduct <= 0 || !Config::getBool(Config::FEAT_CATALOG_SYNC)) {
            return;
        }
        $idShop = (int) Context::getContext()->shop->id;
        $units = PlunkConnect\Catalog\CatalogIds::forProducts([$idProduct], $idShop)[$idProduct] ?? [];
        if ($idAttr > 0) {
            $units = array_intersect_key($units, [$idAttr => true]); // quantity hooks name the exact combination
        }
        foreach ($units as $plunkId) {
            Outbox::enqueue('catalog.patch', ['id' => $plunkId], 'catalog.patch:' . $plunkId, 60);
        }
    }

    /** @var array<int, string[]> plunk ids collected in DeleteBefore hooks */
    private array $deletingProductIds = [];

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

        if (!Config::getBool(Config::FEAT_CATALOG_SYNC)) {
            return;
        }
        $idShop = (int) Context::getContext()->shop->id;
        $lastSync = strtotime((string) Config::get(Config::CATALOG_LAST_SYNC, '1970-01-01', $idShop)) ?: 0;
        $lastFull = strtotime((string) Config::get(Config::CATALOG_FULL_SYNC_AT, '1970-01-01', $idShop)) ?: 0;
        $hour = (int) date('G');
        // Nightly full sync between 03:00 and 05:00, max once per 20 h.
        if ($hour >= 3 && $hour <= 5 && time() - $lastFull > 20 * 3600) {
            PlunkConnect\Service\CatalogSyncService::queueFull($idShop);
        } elseif (time() - $lastSync > 14 * 60) {
            PlunkConnect\Service\CatalogSyncService::queueIncremental($idShop);
        }
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
            if (in_array($key, [Config::WEBHOOK_SECRET, Config::FEED_TOKEN, Config::CRON_TOKEN, Config::COUPON_SECRET], true)) {
                Config::set($key, Config::generateSecret(32));
                $output .= $this->displayConfirmation($this->l('Token regenerated.'));
            }
        }
        if (Tools::isSubmit('plunkRetryDead')) {
            $n = Outbox::retryDead();
            $output .= $this->displayConfirmation(sprintf($this->l('%d dead rows requeued.'), $n));
        }
        if (Tools::isSubmit('plunkCatalogSyncNow')) {
            $stats = PlunkConnect\Service\CatalogSyncService::queueFull((int) Context::getContext()->shop->id);
            $output .= $this->displayConfirmation(sprintf(
                $this->l('Catalog sync queued: %d batch(es), %d product(s).'),
                $stats['batches'],
                $stats['products']
            ));
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
        Config::set(Config::TURNSTILE_SITE_KEY, trim((string) Tools::getValue(Config::TURNSTILE_SITE_KEY)));
        Config::set(Config::TURNSTILE_HOSTNAMES, implode(',', ContactGuardService::parseHostnames((string) Tools::getValue(Config::TURNSTILE_HOSTNAMES))));
        foreach ([Config::PUBLIC_KEY, Config::SECRET_KEY, Config::TURNSTILE_SECRET] as $key) {
            $value = trim((string) Tools::getValue($key));
            if ('' !== $value) {
                Config::set($key, $value);
            }
        }
        foreach ([Config::FEAT_SUBSCRIPTIONS, Config::FEAT_DOUBLE_OPTIN, Config::FEAT_VIEWS,
            Config::FEAT_CART, Config::FEAT_ORDERS, Config::FEAT_FEED,
            Config::FEAT_COUPONS, Config::FEAT_BACKINSTOCK, Config::FEAT_PK_CAPTURE,
            Config::FEAT_CATALOG_SYNC, Config::FEAT_FORMS, Config::FEAT_CONTACT_TURNSTILE, Config::FEAT_NEWSLETTER_TURNSTILE] as $key) {
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

        // Coupon rules JSON: only persist when it decodes to an object.
        $rulesJson = trim((string) Tools::getValue(Config::COUPON_RULES));
        $rules = '' === $rulesJson ? [] : json_decode($rulesJson, true);
        if (!is_array($rules)) {
            return $this->displayError($this->l('Invalid coupon rules JSON - other settings were saved.'));
        }
        Config::set(Config::COUPON_RULES, json_encode($rules));

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

        if (Config::getBool(Config::FEAT_CATALOG_SYNC)) {
            $idShop = (int) Context::getContext()->shop->id;
            $lastSync = (string) Config::get(Config::CATALOG_LAST_SYNC, '', $idShop);
            $lastFull = (string) Config::get(Config::CATALOG_FULL_SYNC_AT, '', $idShop);
            $html .= '<div class="panel"><h4>' . $this->l('Catalog sync') . '</h4>'
                . '<p>' . $this->l('Last incremental') . ': <code>' . htmlspecialchars('' === $lastSync ? '(never)' : $lastSync) . '</code>'
                . ' &middot; ' . $this->l('Last full') . ': <code>' . htmlspecialchars('' === $lastFull ? '(never)' : $lastFull) . '</code></p>'
                . '<form method="post" style="display:inline"><button class="btn btn-default" name="plunkCatalogSyncNow" value="1">'
                . $this->l('Full sync now') . '</button></form> '
                . '<span class="help-block">' . $this->l('Queues catalog.upsert outbox rows; the worker sends them.') . '</span></div>';
        }

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
                    ['type' => 'free', 'label' => $this->l('Coupon secret'), 'name' => 'display_coupon'],
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
                'legend' => ['title' => $this->l('Contact form protection (Cloudflare Turnstile)'), 'icon' => 'icon-shield'],
                'input' => [
                    ['type' => 'text', 'label' => $this->l('Turnstile site key'), 'name' => Config::TURNSTILE_SITE_KEY,
                        'desc' => $this->l('Widget for this shop\'s domains, action contact_form.')],
                    ['type' => 'password', 'label' => $this->l('Turnstile secret key'), 'name' => Config::TURNSTILE_SECRET,
                        'desc' => $this->l('Leave empty to keep current value.')],
                    ['type' => 'text', 'label' => $this->l('Allowed hostnames'), 'name' => Config::TURNSTILE_HOSTNAMES,
                        'desc' => $this->l('Comma-separated, e.g. example.com,www.example.com. Empty = shop domains.')],
                ],
                'submit' => ['title' => $this->l('Save')],
            ]],
            ['form' => [
                'legend' => ['title' => $this->l('Coupon rules'), 'icon' => 'icon-tags'],
                'input' => [
                    ['type' => 'textarea', 'label' => $this->l('Rules (JSON)'), 'name' => Config::COUPON_RULES,
                        'desc' => $this->l('One entry per rule name: {"welcome10": {"type": "percent", "value": 10, "validDays": 30, "minAmount": 30, "prefix": "BZ", "excludeSpecial": true}}. Fields: type percent|amount|free_shipping, value, validDays, minAmount, prefix, excludeSpecial, group, event (informational). Rules sharing a group form a series: a new coupon deactivates the customer\'s earlier unused coupons of that group. Plunk coupons are never combinable with each other.')],
                ],
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
            [Config::FEAT_CATALOG_SYNC, 'Catalog sync to Plunk (products -> /catalog/products)'],
            [Config::FEAT_FORMS, 'Plunk signup forms (embed script; forms are managed in Plunk)'],
            [Config::FEAT_CONTACT_TURNSTILE, 'Contact form protection (Turnstile + rate limit; needs keys below)'],
            [Config::FEAT_NEWSLETTER_TURNSTILE, 'Newsletter form protection (ps_emailsubscription: Turnstile + IP limit; same keys)'],
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
            Config::TURNSTILE_SITE_KEY => Config::get(Config::TURNSTILE_SITE_KEY),
            Config::TURNSTILE_SECRET => '',
            Config::TURNSTILE_HOSTNAMES => Config::get(Config::TURNSTILE_HOSTNAMES),
            Config::CONSENT_SOURCE => Config::get(Config::CONSENT_SOURCE),
            Config::SHOP_CODE => Config::get(Config::SHOP_CODE),
            Config::CONSENT_COOKIE => Config::get(Config::CONSENT_COOKIE),
            Config::CONSENT_COOKIE_VALUE => Config::get(Config::CONSENT_COOKIE_VALUE),
            Config::LOCALE_FALLBACK => LocaleMapper::fallback(),
            'display_webhook' => $mask(Config::WEBHOOK_SECRET),
            'display_feed' => $mask(Config::FEED_TOKEN),
            'display_cron' => $mask(Config::CRON_TOKEN),
            'display_coupon' => $mask(Config::COUPON_SECRET),
            Config::COUPON_RULES => Config::get(Config::COUPON_RULES),
        ];
        foreach ([Config::FEAT_SUBSCRIPTIONS, Config::FEAT_DOUBLE_OPTIN, Config::FEAT_VIEWS,
            Config::FEAT_CART, Config::FEAT_ORDERS, Config::FEAT_FEED,
            Config::FEAT_COUPONS, Config::FEAT_BACKINSTOCK, Config::FEAT_PK_CAPTURE,
            Config::FEAT_CATALOG_SYNC, Config::FEAT_FORMS, Config::FEAT_CONTACT_TURNSTILE, Config::FEAT_NEWSLETTER_TURNSTILE] as $key) {
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
