<?php

/**
 * POST /module/plunkconnect/webhook
 * Plunk workflow WEBHOOK step -> PS subscription flags.
 * Auth: X-Plunk-Timestamp + X-Plunk-Signature HMAC, or Bearer secret fallback.
 */
require_once dirname(__DIR__, 2) . '/autoload.php';

use PlunkConnect\Config;
use PlunkConnect\Service\RequestAuth;
use PlunkConnect\Service\SubscriptionService;

class PlunkconnectWebhookModuleFrontController extends ModuleFrontController
{
    public function postProcess(): void
    {
        if ('POST' !== strtoupper((string) $_SERVER['REQUEST_METHOD'])) {
            $this->respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
        }

        $rawBody = (string) file_get_contents('php://input');
        if (!RequestAuth::verifySignedRequest($rawBody)) {
            $this->respond(401, ['ok' => false, 'error' => 'unauthorized']);
        }

        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            $this->respond(400, ['ok' => false, 'error' => 'invalid_json']);
        }

        $event = (string) ($body['event'] ?? $body['type'] ?? '');
        $email = strtolower(trim((string) (
            $body['email']
            ?? $body['data']['email']
            ?? $body['contact']['email']
            ?? ''
        )));

        if (!in_array($event, ['contact.subscribed', 'contact.unsubscribed'], true)) {
            // Unknown events are acknowledged but ignored (idempotent).
            $this->respond(200, ['ok' => true, 'ignored' => '' !== $event ? $event : 'empty']);
        }
        if (!Validate::isEmail($email)) {
            $this->respond(400, ['ok' => false, 'error' => 'invalid_email']);
        }

        // The shop receiving the request scopes the update; a shopCode that
        // does not match this shop's config is rejected outright.
        if (isset($body['shopCode'])
            && (string) $body['shopCode'] !== (string) Config::get(Config::SHOP_CODE)) {
            $this->respond(400, ['ok' => false, 'error' => 'shop_code_mismatch']);
        }
        $idShop = (int) Context::getContext()->shop->id;

        $result = SubscriptionService::applyWebhook($email, 'contact.subscribed' === $event, $idShop);

        $this->respond(200, ['ok' => true, 'event' => $event] + $result);
    }

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        exit(json_encode($body));
    }
}
