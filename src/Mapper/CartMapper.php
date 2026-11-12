<?php

/**
 * Cart -> event payload (non-persistent `cart` object) including the
 * signed restoreUrl for abandoned-cart flows.
 */

namespace PlunkConnect\Mapper;

use PlunkConnect\Service\CartRestoreService;

final class CartMapper
{
    private ProductMapper $products;

    public function __construct(?ProductMapper $products = null)
    {
        $this->products = $products ?: new ProductMapper();
    }

    public function map(\Cart $cart, int $idShop, int $idLang): array
    {
        $items = [];
        foreach ((array) $cart->getProducts() as $row) {
            $item = $this->products->mapLine($row, $idShop, $idLang);
            if ($item) {
                $items[] = $item;
            }
        }

        return [
            'id' => (int) $cart->id,
            'items' => $items,
            'total' => \Tools::ps_round(
                (float) $cart->getOrderTotal(true, \Cart::BOTH),
                \Context::getContext()->getComputingPrecision()
            ),
            'currency' => (string) \Context::getContext()->currency->iso_code,
            'restoreUrl' => CartRestoreService::url($cart),
        ];
    }
}
