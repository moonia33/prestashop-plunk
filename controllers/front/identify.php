<?php

/**
 * POST /module/plunkconnect/identify (email, token)
 * Called by the page after a Plunk form signup: flushes the visitor's
 * buffered anonymous product views to that email as one `product.viewed`.
 * The email is what the visitor typed (unverified), so the call never
 * identifies the browser for later tracking and each nonce works once:
 * it is cleared with the buffer, expires after 48 h and, because the PS
 * cookie can be replayed, is also recorded server-side as the outbox
 * dedupe key.
 */
require_once dirname(__DIR__, 2) . '/autoload.php';

use PlunkConnect\Config;
use PlunkConnect\Outbox\Outbox;
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
        $dedupeKey = 'identify:' . hash('sha256', (string) Tools::getValue('token'));
        if (Outbox::exists($dedupeKey, (int) $context->shop->id)) {
            $this->respond(403);
        }
        if (null === IdentityService::email($context)) {
            TrackingService::flushAnonymousViews($email, (int) $context->language->id, $dedupeKey);
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
