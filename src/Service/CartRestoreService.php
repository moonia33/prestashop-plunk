<?php

/**
 * Signed cart-restore links for abandoned-cart emails.
 *
 * restoreUrl = /module/plunkconnect/restorecart?c=<id>&e=<exp>&t=<hmac>
 * hmac = HMAC_SHA256(secret, "<id>|<secure_key>|<exp>")
 * The cart's own secure_key is mixed in, so a token cannot be forged for a
 * foreign cart and the link naturally dies with the cart.
 */

namespace PlunkConnect\Service;

use PlunkConnect\Config;
use PlunkConnect\Security\HmacService;

final class CartRestoreService
{
    public const TOKEN_DAYS = 30;

    public static function url(\Cart $cart): ?string
    {
        $secret = (string) Config::get(Config::WEBHOOK_SECRET);
        if ('' === $secret || empty($cart->secure_key)) {
            return null;
        }
        $expires = time() + self::TOKEN_DAYS * 86400;
        $hmac = new HmacService($secret);

        $base = \Context::getContext()->link->getModuleLink(
            'plunkconnect',
            'restorecart',
            [],
            \Tools::usingSecureMode()
        );

        return $base
            . (false === strpos($base, '?') ? '?' : '&')
            . 'c=' . (int) $cart->id
            . '&e=' . $expires
            . '&t=' . $hmac->sign((int) $cart->id . '|' . $cart->secure_key . '|' . $expires)
            . '&utm_source=plunk&utm_medium=email&utm_campaign=abandoned_cart';
    }

    /**
     * Verify token + expiry and load the cart if it is still restorable.
     */
    public static function verify(int $idCart, int $expires, string $token): ?\Cart
    {
        if ($expires < time() || '' === $token) {
            return null;
        }
        $cart = new \Cart($idCart);
        if (!\Validate::isLoadedObject($cart) || empty($cart->secure_key)) {
            return null;
        }
        $secret = (string) Config::get(Config::WEBHOOK_SECRET);
        if ('' === $secret) {
            return null;
        }
        $hmac = new HmacService($secret);
        $expected = $idCart . '|' . $cart->secure_key . '|' . $expires;
        if (!$hmac->verify($expected, $token)) {
            return null;
        }
        // A cart already converted to an order is not restorable.
        if ($cart->orderExists()) {
            return null;
        }

        return $cart;
    }
}
