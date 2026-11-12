<?php

/**
 * GET /module/plunkconnect/restorecart?c=<id>&e=<exp>&t=<hmac>
 * Restores an abandoned cart: re-attaches it to the session cookie, or
 * redirects to login (with back= to the cart) when the cart belongs to a
 * registered customer.
 */
require_once dirname(__DIR__, 2) . '/autoload.php';

use PlunkConnect\Service\CartRestoreService;

class PlunkconnectRestorecartModuleFrontController extends ModuleFrontController
{
    public function postProcess(): void
    {
        $idCart = (int) Tools::getValue('c');
        $expires = (int) Tools::getValue('e');
        $token = (string) Tools::getValue('t');

        $cart = CartRestoreService::verify($idCart, $expires, $token);
        if (!$cart) {
            // Invalid/expired link: just send the visitor to the home page.
            Tools::redirect('index.php');
            exit;
        }

        $context = Context::getContext();
        if ($cart->id_customer) {
            // Registered customer: require login, then go to the cart.
            $back = urlencode('index.php?controller=order&step=1');
            Tools::redirect('index.php?controller=authentication&back=' . $back);
            exit;
        }

        // Guest cart: adopt it into the session.
        $context->cookie->id_cart = (int) $cart->id;
        $context->cookie->write();
        Tools::redirect('index.php?controller=cart&action=show');
        exit;
    }
}
