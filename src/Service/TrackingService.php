<?php

/**
 * Behavioural events: product/category views, carts, orders, back-in-stock.
 * Everything goes through the outbox; hooks add < 5 ms.
 */

namespace PlunkConnect\Service;

use PlunkConnect\Catalog\CatalogIds;
use PlunkConnect\Config;
use PlunkConnect\Mapper\CartMapper;
use PlunkConnect\Mapper\CustomerMapper;
use PlunkConnect\Mapper\OrderMapper;
use PlunkConnect\Mapper\ProductMapper;
use PlunkConnect\Outbox\Outbox;

final class TrackingService
{
    private const VIEW_DEBOUNCE_SECONDS = 1800; // 30 min per contact+product

    /**
     * `actionFrontControllerSetMedia` handler - detects product/category
     * controllers and queues the corresponding view event.
     */
    public static function onFrontControllerSetMedia(): void
    {
        if (!Config::getBool(Config::FEAT_VIEWS) || !Config::isConfigured() || IdentityService::isBot()) {
            return;
        }
        if (!ConsentService::marketingAllowed()) {
            return;
        }

        IdentityService::captureEmailToken();

        $context = \Context::getContext();
        $controller = $context->controller ?? null;
        $phpSelf = is_object($controller) ? ($controller->php_self ?? '') : '';
        $idShop = (int) ($context->shop->id ?? 0);
        $idLang = (int) ($context->language->id ?? 0);

        if ('product' === $phpSelf) {
            $idProduct = (int) \Tools::getValue('id_product');
            $idAttr = (int) \Tools::getValue('id_product_attribute');
            if ($idProduct > 0) {
                self::productViewed($idProduct, $idShop, $idLang, $idAttr);
            }
        } elseif ('category' === $phpSelf) {
            $idCategory = (int) \Tools::getValue('id_category');
            if ($idCategory > 0) {
                self::categoryViewed($idCategory, $idShop, $idLang);
            }
        } elseif ('order' === $phpSelf && $context->cart && $context->cart->id) {
            self::checkoutStarted($context->cart, $idShop, $idLang);
        }
    }

    /**
     * First visit to the checkout. Collapsed per cart so refreshes and
     * step navigation emit a single `checkout.started`.
     */
    public static function checkoutStarted(\Cart $cart, int $idShop, int $idLang): void
    {
        if (!Config::getBool(Config::FEAT_CART) || !Config::isConfigured()) {
            return;
        }
        $email = IdentityService::email();
        if (!$email) {
            return;
        }
        // Once per cart: a cookie marker stops repeat page loads in the
        // same session, and outbox collapse covers the rest.
        $cookieKey = 'plunk_co_' . (int) $cart->id;
        if (!empty(\Context::getContext()->cookie->{$cookieKey})) {
            return;
        }
        \Context::getContext()->cookie->{$cookieKey} = 1;

        $data = CustomerMapper::base($idLang, $idShop);
        $data['cart'] = [
            'value' => (new CartMapper())->map($cart, $idShop, $idLang),
            'persistent' => false,
        ];

        Outbox::enqueue('track', [
            'event' => 'checkout.started',
            'email' => $email,
            'data' => $data,
        ], 'checkout:' . (int) $cart->id);
    }

    public static function productViewed(int $idProduct, int $idShop, int $idLang, int $idProductAttribute = 0): void
    {
        $email = IdentityService::email();
        if (!$email) {
            // Internal id only; resolved to catalog ids when the visitor is identified.
            IdentityService::recordAnonymousView(CatalogIds::fallback($idProduct, $idProductAttribute));

            return;
        }
        if (!self::viewDebounceAllows($email, $idProduct)) {
            return;
        }
        $product = (new ProductMapper())->mapProduct($idProduct, $idShop, $idLang, $idProductAttribute);
        if (!$product) {
            return;
        }

        $data = CustomerMapper::base($idLang, $idShop);
        $data['lastViewedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        $data['recentlyViewed'] = self::mergeRecentlyViewed($email, (string) $product['productId']);
        $data['product'] = ['value' => $product, 'persistent' => false];

        Outbox::enqueue('track', [
            'event' => 'product.viewed',
            'email' => $email,
            'data' => $data,
        ]);
    }

    public static function categoryViewed(int $idCategory, int $idShop, int $idLang): void
    {
        $email = IdentityService::email();
        if (!$email) {
            return;
        }
        $category = (new ProductMapper())->mapCategory($idCategory, $idShop, $idLang);
        if (!$category) {
            return;
        }

        $data = CustomerMapper::base($idLang, $idShop);
        $data['lastCategoryId'] = $idCategory;
        $data['category'] = ['value' => $category, 'persistent' => false];

        Outbox::enqueue('track', [
            'event' => 'category.viewed',
            'email' => $email,
            'data' => $data,
        ]);
    }

    /**
     * Cart save/update. Outbox collapse on `cart:<id>` turns a burst of
     * quick changes into a single `cart.updated` with the latest state.
     */
    public static function cartUpdated(\Cart $cart, int $idLang = 0): void
    {
        if (!Config::getBool(Config::FEAT_CART) || !Config::isConfigured()) {
            return;
        }
        if (!\Validate::isLoadedObject($cart)
            || (method_exists($cart, 'isGuestCartByEmaillessCustomer') && $cart->isGuestCartByEmaillessCustomer())) {
            return;
        }
        $context = \Context::getContext();
        $idShop = (int) ($context->shop->id ?? 0);
        $idLang = $idLang ?: (int) ($context->language->id ?? 0);

        $email = IdentityService::email();
        if (!$email) {
            return;
        }

        // Login mid-session also identifies the visitor: flush buffered views.
        self::flushAnonymousViews($email, $idLang);

        $cartData = (new CartMapper())->map($cart, $idShop, $idLang);
        $itemsCount = array_sum(array_column($cartData['items'], 'quantity'));

        $data = CustomerMapper::base($idLang, $idShop);
        $data['cartItemsCount'] = (int) $itemsCount;
        $data['cartTotal'] = $cartData['total'];
        $data['cartUpdatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        $data['cart'] = ['value' => $cartData, 'persistent' => false];

        Outbox::enqueue('track', [
            'event' => 'cart.updated',
            'email' => $email,
            'data' => $data,
        ], 'cart:' . (int) $cart->id);
    }

    /**
     * `actionValidateOrder` - the order was just created.
     */
    public static function orderPlaced(array $params): void
    {
        if (!Config::getBool(Config::FEAT_ORDERS) || !Config::isConfigured()) {
            return;
        }
        $order = $params['order'] ?? null;
        $customer = $params['customer'] ?? null;
        if (!$order instanceof \Order || !$customer instanceof \Customer) {
            return;
        }
        $idShop = (int) $order->id_shop;
        $idLang = (int) $customer->id_lang;
        $email = strtolower((string) $customer->email);
        if (!\Validate::isEmail($email)) {
            return;
        }

        $mapper = new OrderMapper();
        $data = CustomerMapper::base($idLang, $idShop);
        $data += CustomerMapper::contact($customer, $idShop);
        $data += $mapper->contactAggregates($email, $idShop);
        $data['cartItemsCount'] = 0;
        $data['order'] = [
            'value' => $mapper->map($order, $idShop, $idLang, $mapper->isFirstOrder($order)),
            'persistent' => false,
        ];

        Outbox::enqueue('track', [
            'event' => 'order.placed',
            'email' => $email,
            'data' => $data,
        ]);

        // A checkout newsletter opt-in is consent: queue a signup too.
        if (!empty($customer->newsletter)) {
            SubscriptionService::signup($email, [], $customer);
        }
    }

    /**
     * `actionOrderStatusPostUpdate` - map the new OrderState to an event.
     */
    public static function orderStatusUpdated(array $params): void
    {
        if (!Config::getBool(Config::FEAT_ORDERS) || !Config::isConfigured()) {
            return;
        }
        $orderState = $params['newOrderStatus'] ?? null;
        $idOrder = (int) ($params['id_order'] ?? 0);
        if (!$orderState instanceof \OrderState || $idOrder <= 0) {
            return;
        }
        $event = OrderMapper::eventForState((int) $orderState->id);
        if (null === $event) {
            return;
        }
        $order = new \Order($idOrder);
        if (!\Validate::isLoadedObject($order)) {
            return;
        }
        $customer = new \Customer((int) $order->id_customer);
        $email = \Validate::isLoadedObject($customer) ? strtolower((string) $customer->email) : '';
        if (!\Validate::isEmail($email)) {
            return;
        }
        $idShop = (int) $order->id_shop;
        $idLang = (int) ($customer->id_lang ?: \Context::getContext()->language->id);

        $mapper = new OrderMapper();
        $data = CustomerMapper::base($idLang, $idShop);
        // Totals change on cancel/refund (order no longer valid); status is the latest order's.
        $data += $mapper->contactAggregates($email, $idShop);
        $orderData = $mapper->map($order, $idShop, $idLang);
        $trackingUrl = self::trackingUrl($order);
        if ($trackingUrl) {
            $orderData['trackingUrl'] = $trackingUrl;
        }
        $data['order'] = ['value' => $orderData, 'persistent' => false];

        Outbox::enqueue('track', [
            'event' => 'order.' . $event,
            'email' => $email,
            'data' => $data,
        ], 'order:' . $idOrder . ':' . $event);
    }

    /**
     * `customer.registered` event on account creation.
     */
    public static function customerRegistered(\Customer $customer): void
    {
        if (!Config::isConfigured()) {
            return;
        }
        $idLang = (int) $customer->id_lang;
        $data = CustomerMapper::contact($customer);
        $data['registeredAt'] = gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $customer->date_add ?: 'now'));

        Outbox::enqueue('track', [
            'event' => 'customer.registered',
            'email' => strtolower((string) $customer->email),
            'data' => $data,
        ]);

        // Anonymous views collected before registration flush as one event.
        self::flushAnonymousViews(strtolower((string) $customer->email), $idLang);
    }

    /**
     * One `product.viewed` event carrying the anonymous view history.
     */
    public static function flushAnonymousViews(string $email, int $idLang, ?string $dedupeKey = null): void
    {
        $views = IdentityService::drainAnonymousViews();
        if (!$views) {
            return;
        }
        $idShop = (int) (\Context::getContext()->shop->id ?? 0);
        $products = [];
        $mapper = new ProductMapper();
        $views = array_map('strval', array_slice($views, -10));
        $units = CatalogIds::resolve($views, $idShop);
        foreach ($views as $plunkId) {
            [$idProduct, $idAttr] = $units[$plunkId] ?? [0, 0];
            $p = $idProduct > 0 ? $mapper->mapProduct($idProduct, $idShop, $idLang, $idAttr) : null;
            if ($p) {
                $products[] = $p;
            }
        }
        if (!$products) {
            return;
        }

        $data = CustomerMapper::base($idLang, $idShop);
        // Catalog ids (SKU or PS-<id>[-<attr>]) to match the Plunk catalog.
        $data['recentlyViewed'] = array_column($products, 'productId');
        $data['lastViewedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        $data['products'] = ['value' => $products, 'persistent' => false];

        Outbox::enqueue('track', [
            'event' => 'product.viewed',
            'email' => $email,
            'data' => $data,
        ], $dedupeKey);
    }

    /**
     * Back-in-stock: quantity went 0 -> >0; notify emails waiting in
     * ps_emailalerts (mailalert_customer_oos).
     */
    public static function backInStock(int $idProduct, int $idProductAttribute, int $quantity): void
    {
        if (!Config::getBool(Config::FEAT_BACKINSTOCK) || !Config::isConfigured() || $quantity <= 0) {
            return;
        }
        if (!\Db::getInstance()->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'mailalert_customer_oos\'')) {
            return;
        }
        $idShop = (int) (\Context::getContext()->shop->id ?? 0);
        $idLang = (int) (\Context::getContext()->language->id ?? 0);
        $product = (new ProductMapper())->mapProduct($idProduct, $idShop, $idLang, $idProductAttribute);
        if (!$product || !$product['inStock']) {
            return;
        }
        $emails = \Db::getInstance()->executeS(
            'SELECT DISTINCT `customer_email`
             FROM `' . _DB_PREFIX_ . 'mailalert_customer_oos`
             WHERE `id_product` = ' . $idProduct . '
               AND `id_product_attribute` = ' . $idProductAttribute . '
               AND `id_shop` = ' . $idShop . '
             LIMIT 500'
        ) ?: [];

        foreach ($emails as $row) {
            $email = strtolower((string) $row['customer_email']);
            if (!\Validate::isEmail($email)) {
                continue;
            }
            $data = CustomerMapper::base($idLang, $idShop);
            $data['product'] = ['value' => $product, 'persistent' => false];
            Outbox::enqueue('track', [
                'event' => 'product.back_in_stock',
                'email' => $email,
                'data' => $data,
            ], 'bis:' . $idProduct . ':' . $idProductAttribute . ':' . md5($email));
        }
    }

    /**
     * 30-minute debounce for identical contact+product views, kept in the
     * encrypted PS cookie (no table needed for this small map).
     */
    private static function viewDebounceAllows(string $email, int $idProduct): bool
    {
        $context = \Context::getContext();
        $key = 'plunk_vd';
        $map = [];
        if (!empty($context->cookie->{$key})) {
            $decoded = json_decode((string) $context->cookie->{$key}, true);
            $map = is_array($decoded) ? $decoded : [];
        }
        $now = time();
        // Prune expired entries to keep the cookie small.
        $map = array_filter($map, fn ($ts) => is_int($ts) && $now - $ts < self::VIEW_DEBOUNCE_SECONDS);
        $cookieKey = $idProduct . ':' . substr(md5($email), 0, 8);
        if (isset($map[$cookieKey])) {
            $context->cookie->{$key} = json_encode($map);

            return false;
        }
        $map[$cookieKey] = $now;
        $context->cookie->{$key} = json_encode($map);

        return true;
    }

    /**
     * Recently-viewed list kept in the visitor cookie as Plunk catalog ids
     * so the payload matches the catalog.
     *
     * @return string[]
     */
    private static function mergeRecentlyViewed(string $email, string $plunkId): array
    {
        $context = \Context::getContext();
        $key = 'plunk_rv';
        $list = [];
        if (!empty($context->cookie->{$key})) {
            $decoded = json_decode((string) $context->cookie->{$key}, true);
            $list = is_array($decoded) ? array_map('strval', $decoded) : [];
        }
        $list = array_values(array_filter($list, fn ($id) => $id !== $plunkId));
        $list[] = $plunkId;
        $list = array_slice($list, -10);
        $context->cookie->{$key} = json_encode($list);

        return $list;
    }

    private static function trackingUrl(\Order $order): ?string
    {
        $shipping = $order->getShipping();
        $number = (string) ($shipping[0]['tracking_number'] ?? '');
        if ('' === $number) {
            return null;
        }
        $carrier = new \Carrier((int) $order->id_carrier);
        $url = (string) $carrier->url;
        if ('' === $url) {
            return null;
        }

        return str_replace('@', $number, $url);
    }
}
