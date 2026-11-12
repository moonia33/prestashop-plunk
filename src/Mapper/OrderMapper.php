<?php

/**
 * Order -> event payload plus persistent contact aggregates
 * (ordersCount, totalSpent, lastOrderAt, lastOrderValue).
 */

namespace PlunkConnect\Mapper;

final class OrderMapper
{
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

        return [
            'id' => (int) $order->id,
            'reference' => (string) $order->reference,
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
     * Persistent contact aggregates recomputed after an order.
     */
    public function contactAggregates(\Order $order, string $email, int $idShop): array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT COUNT(o.`id_order`) AS cnt, COALESCE(SUM(o.`total_paid_tax_incl`), 0) AS spent,
                    MAX(o.`date_add`) AS last_at
             FROM `' . _DB_PREFIX_ . 'orders` o
             INNER JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = o.`id_customer`
             WHERE c.`email` = \'' . pSQL($email) . '\'
               AND o.`id_shop` = ' . $idShop . '
               AND o.`valid` = 1'
        );

        return [
            'ordersCount' => (int) ($row['cnt'] ?? 0),
            'totalSpent' => \Tools::ps_round((float) ($row['spent'] ?? 0), \Context::getContext()->getComputingPrecision()),
            'lastOrderAt' => !empty($row['last_at']) ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $row['last_at'])) : null,
            'lastOrderValue' => \Tools::ps_round((float) $order->total_paid_tax_incl, \Context::getContext()->getComputingPrecision()),
            'isCustomer' => true,
            'cartItemsCount' => 0,
        ];
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
