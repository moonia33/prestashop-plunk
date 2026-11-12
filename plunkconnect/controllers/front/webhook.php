<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * POST /module/plunkconnect/webhook
 *
 * Inbound endpoint for Plunk workflow WEBHOOK steps (contact.subscribed /
 * contact.unsubscribed etc.). Auth: Authorization: Bearer <webhook secret>
 * or HMAC (X-Plunk-Timestamp + X-Plunk-Signature) — see
 * WebhookAuthenticator.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use PlunkConnect\Config\ModuleConfiguration;
use PlunkConnect\Newsletter\WebhookHandler;
use PlunkConnect\Security\WebhookAuthenticator;

class PlunkconnectWebhookModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $auth = false;
    /** @var bool */
    public $guestAllowed = true;
    /** @var bool */
    public $ssl = true;

    public function postProcess(): void
    {
        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) {
            $this->jsonResponse(405, ['error' => 'method not allowed']);
        }

        $module = $this->module;
        if (!$module instanceof Plunkconnect) {
            $this->jsonResponse(500, ['error' => 'module unavailable']);
        }
        $config = $module->getConfiguration();
        $idShop = (int) $this->context->shop->id;

        $secret = $config->get(ModuleConfiguration::WEBHOOK_SECRET, $idShop);
        $rawBody = (string) file_get_contents('php://input');
        if (strlen($rawBody) > 65536) {
            $this->jsonResponse(413, ['error' => 'payload too large']);
        }

        $authenticator = new WebhookAuthenticator($secret);
        if (!$authenticator->isValid($rawBody, $this->requestHeaders())) {
            $this->jsonResponse(401, ['error' => 'unauthorized']);
        }

        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            $this->jsonResponse(400, ['error' => 'invalid json']);
        }

        $subscription = WebhookHandler::extractSubscription($body);
        if (null === $subscription) {
            $this->jsonResponse(422, ['error' => 'no subscription decision in payload']);
        }

        try {
            $result = (new WebhookHandler())->applySubscription(
                $subscription['email'],
                $subscription['subscribed'],
                $idShop,
                (int) $this->context->language->id
            );
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('PlunkConnect webhook failed: ' . $e->getMessage(), 3, null, 'PlunkConnect', null, true);
            $this->jsonResponse(500, ['error' => 'internal error']);
        }

        $this->jsonResponse(200, ['success' => true, 'changed' => $result['changed']]);
    }

    /**
     * @return array<string, string> lowercase header names
     */
    private function requestHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (0 === strpos($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return never
     */
    private function jsonResponse(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($payload);
        exit;
    }
}
