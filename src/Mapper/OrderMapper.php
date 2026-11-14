<?php

/**
 * Order -> event payload plus persistent contact aggregates
 * (ordersCount, totalSpent, aov, first/lastOrderAt, lastOrderValue,
 * lastOrderStatus, purchasedCategories/Brands/Activities).
 */

namespace PlunkConnect\Mapper;

final class OrderMapper
{
    private const PURCHASED_SCAN = 2000;
    private const PURCHASED_MAX = 50;

    private ProductMapper $products;

    public function __construct(?ProductMapper $products = null)
    {
        $this->products = $products ?: new ProductMapper();
    }

    /**
     * Non-persistent `order` object for order.* events.
     */
    public function map(\Order $order, int $idShop, int $idLang, bool $isFirstOrder = false): array
    {
        $precision = \Context::getContext()->getComputingPrecision();
        $items = [];
        foreach ((array) $order->getProducts() as $row) {
            $item = $this->products->mapLine(
                [
                    'id_product' => $row['product_id'],
                    'id_product_attribute' => $row['product_attribute_id'],
                    'product_quantity' => $row['product_quantity'],
                    'total_wt' => $row['total_price_tax_incl'],
                ],
                $idShop,
                $idLang
            );
            if ($item) {
                $item['unitPrice'] = \Tools::ps_round((float) $row['unit_price_tax_incl'], $precision);
                $items[] = $item;
            }
        }

        $currency = new \Currency((int) $order->id_currency);
        $couponCode = \Db::getInstance()->getValue(
            'SELECT cr.`code` FROM `' . _DB_PREFIX_ . 'order_cart_rule` ocr
             INNER JOIN `' . _DB_PREFIX_ . 'cart_rule` cr ON cr.`id_cart_rule` = ocr.`id_cart_rule`
             WHERE ocr.`id_order` = ' . (int) $order->id . '
             ORDER BY ocr.`id_order_cart_rule` LIMIT 1'
        );

        return [
            'id' => (int) $order->id,
            'reference' => (string) $order->reference,
            'couponCode' => $couponCode ? (string) $couponCode : null,
            'total' => \Tools::ps_round((float) $order->total_paid_tax_incl, $precision),
            'shipping' => \Tools::ps_round((float) $order->total_shipping_tax_incl, $precision),
            'discounts' => \Tools::ps_round((float) $order->total_discounts_tax_incl, $precision),
            'items' => $items,
            'payment' => (string) $order->payment,
            'currency' => (string) $currency->iso_code,
            'isFirstOrder' => $isFirstOrder,
        ];
    }

    /**
     * Persistent contact fields from the customer's order history in the
     * shop (all customer accounts sharing the email): totals, dates, last
     * order status and what was bought (category slugs incl. parents,
     * brands, activity slugs).
     *
     * @return array<string, mixed>
     */
    public function contactAggregates(string $email, int $idShop): array
    {
        $db = \Db::getInstance();
        $precision = \Context::getContext()->getComputingPrecision();
        $from = ' FROM `' . _DB_PREFIX_ . 'orders` o
             INNER JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = o.`id_customer`
             WHERE c.`email` = \'' . pSQL($email) . '\' AND o.`id_shop` = ' . $idShop;

        $totals = $db->getRow(
            'SELECT COUNT(o.`id_order`) AS cnt, COALESCE(SUM(o.`total_paid_tax_incl`), 0) AS spent,
                    MIN(o.`date_add`) AS first_at, MAX(o.`date_add`) AS last_at' . $from . ' AND o.`valid` = 1'
        ) ?: [];
        $lastValue = $db->getValue(
            'SELECT o.`total_paid_tax_incl`' . $from . ' AND o.`valid` = 1 ORDER BY o.`date_add` DESC, o.`id_order` DESC'
        );
        $lastState = $db->getValue(
            'SELECT o.`current_state`' . $from . ' ORDER BY o.`date_add` DESC, o.`id_order` DESC'
        );

        $count = (int) ($totals['cnt'] ?? 0);
        $spent = (float) ($totals['spent'] ?? 0);

        return [
            'ordersCount' => $count,
            'totalSpent' => \Tools::ps_round($spent, $precision),
            'aov' => $count > 0 ? \Tools::ps_round($spent / $count, $precision) : 0.0,
            'firstOrderAt' => self::isoDate($totals['first_at'] ?? null),
            'lastOrderAt' => self::isoDate($totals['last_at'] ?? null),
            'lastOrderValue' => \Tools::ps_round((float) $lastValue, $precision),
            'lastOrderStatus' => false !== $lastState && null !== $lastState
                ? (self::eventForState((int) $lastState, $idShop) ?? 'placed')
                : null,
            'isCustomer' => $count > 0,
        ] + $this->purchased($email, $idShop);
    }

    /**
     * Activity/category slug: lower-case ASCII words joined by "-"
     * ("Dugninė žvejyba" -> "dugnine-zvejyba").
     */
    public static function slug(string $value): string
    {
        $map = [
            'ą' => 'a', 'č' => 'c', 'ę' => 'e', 'ė' => 'e', 'į' => 'i', 'š' => 's', 'ų' => 'u', 'ū' => 'u', 'ž' => 'z',
            'ā' => 'a', 'ē' => 'e', 'ģ' => 'g', 'ī' => 'i', 'ķ' => 'k', 'ļ' => 'l', 'ņ' => 'n', 'ō' => 'o',
            'õ' => 'o', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u',
            'ć' => 'c', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ];
        $value = strtr(mb_strtolower(trim($value)), $map);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $value), '-');
    }

    /**
     * @return array{purchasedCategories:string[],purchasedBrands:string[],purchasedActivities?:string[]}
     */
    private function purchased(string $email, int $idShop): array
    {
        $db = \Db::getInstance();
        $rows = $db->executeS(
            'SELECT DISTINCT od.`product_id` AS id_product, p.`id_category_default`, m.`name` AS brand
             FROM `' . _DB_PREFIX_ . 'order_detail` od
             INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = od.`id_order`
             INNER JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = o.`id_customer`
             LEFT JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = od.`product_id`
             LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON m.`id_manufacturer` = p.`id_manufacturer`
             WHERE c.`email` = \'' . pSQL($email) . '\' AND o.`id_shop` = ' . $idShop . ' AND o.`valid` = 1
             LIMIT ' . self::PURCHASED_SCAN
        ) ?: [];

        $productIds = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'id_product')))));
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'id_category_default')))));
        $brands = array_values(array_unique(array_filter(array_map(fn ($b) => trim((string) $b), array_column($rows, 'brand')))));
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

        $categories = [];
        if ($categoryIds) {
            $skip = [(int) \Configuration::get('PS_ROOT_CATEGORY'), (int) \Configuration::get('PS_HOME_CATEGORY')];
            $categories = array_column($db->executeS(
                'SELECT DISTINCT cl.`link_rewrite`
                 FROM `' . _DB_PREFIX_ . 'category` child
                 INNER JOIN `' . _DB_PREFIX_ . 'category` parent
                    ON parent.`nleft` <= child.`nleft` AND parent.`nright` >= child.`nright`
                 INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                    ON cl.`id_category` = parent.`id_category` AND cl.`id_lang` = ' . $idLang . ' AND cl.`id_shop` = ' . $idShop . '
                 WHERE child.`id_category` IN (' . implode(',', $categoryIds) . ')
                   AND parent.`id_category` NOT IN (' . implode(',', $skip) . ')
                 LIMIT ' . self::PURCHASED_MAX
            ) ?: [], 'link_rewrite');
        }

        $out = [
            'purchasedCategories' => array_values(array_unique(array_map('strval', $categories))),
            'purchasedBrands' => array_slice($brands, 0, self::PURCHASED_MAX),
        ];

        $idFeature = (int) \PlunkConnect\Config::get(\PlunkConnect\Config::ACTIVITY_FEATURE_ID, 0, $idShop);
        if ($idFeature > 0) {
            $values = $productIds ? array_column($db->executeS(
                'SELECT DISTINCT fvl.`value`
                 FROM `' . _DB_PREFIX_ . 'feature_product` fp
                 INNER JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl
                    ON fvl.`id_feature_value` = fp.`id_feature_value` AND fvl.`id_lang` = ' . $idLang . '
                 WHERE fp.`id_feature` = ' . $idFeature . ' AND fp.`id_product` IN (' . implode(',', $productIds) . ')
                 LIMIT ' . self::PURCHASED_MAX
            ) ?: [], 'value') : [];
            $out['purchasedActivities'] = array_values(array_unique(array_filter(array_map(
                fn ($v) => self::slug((string) $v),
                $values
            ))));
        }

        return $out;
    }

    private static function isoDate(mixed $value): ?string
    {
        return !empty($value) ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime((string) $value)) : null;
    }

    /**
     * Whether this order is the customer's first valid order in the shop.
     */
    public function isFirstOrder(\Order $order): bool
    {
        return 0 === (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders`
             WHERE `id_customer` = ' . (int) $order->id_customer . '
               AND `id_shop` = ' . (int) $order->id_shop . '
               AND `valid` = 1 AND `id_order` != ' . (int) $order->id
        );
    }

    /**
     * Configured order-state -> Plunk event mapping lookup.
     *
     * @return string|null e.g. `paid`, `shipped`, `delivered`, `cancelled`, `refunded`
     */
    public static function eventForState(int $idOrderState, ?int $idShop = null): ?string
    {
        $map = \PlunkConnect\Config::getJson(\PlunkConnect\Config::ORDERSTATE_MAP, $idShop);
        foreach (['paid', 'shipped', 'delivered', 'cancelled', 'refunded'] as $event) {
            if (in_array($idOrderState, array_map('intval', (array) ($map[$event] ?? [])), true)) {
                return $event;
            }
        }

        return null;
    }
}
