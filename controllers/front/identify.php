<?php

/**
 * POST /module/plunkconnect/identify (email, token)
 * Called by the page after a Plunk form signup: flushes the visitor's
 * buffered anonymous product views to that email as one `product.viewed`.
 * The email is what the visitor typed (unverified), so the call is
 * single-use per session (the nonce is cleared with the buffer) and never
 * identifies the browser for later tracking.
 */
require_once dirname(__DIR__, 2) . '/autoload.php';

use PlunkConnect\Config;
use PlunkConnect\Service\ConsentService;
use PlunkConnect\Service\IdentityService;
use PlunkConnect\Service\TrackingService;

class PlunkconnectIdentifyModuleFrontController extends ModuleFrontController
{
    public function postProcess(): void
    {
        if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) {
            $this->respond(405);
        }
        if (!Config::getBool(Config::FEAT_VIEWS) || !Config::isConfigured() || IdentityService::isBot()
            || !ConsentService::marketingAllowed()) {
            $this->respond(204);
        }
        if (!IdentityService::verifyIdentifyNonce((string) Tools::getValue('token'))) {
            $this->respond(403);
        }
        $email = strtolower(trim((string) Tools::getValue('email')));
        if (!Validate::isEmail($email)) {
            $this->respond(400);
        }

        $context = Context::getContext();
        if (null === IdentityService::email($context)) {
            TrackingService::flushAnonymousViews($email, (int) $context->language->id);
        }
        IdentityService::drainAnonymousViews();
        $context->cookie->write();
        $this->respond(204);
    }

    private function respond(int $status): void
    {
        http_response_code($status);
        header('Cache-Control: no-store');
        exit;
    }
}
