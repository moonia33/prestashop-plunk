<?php

/**
 * POST /module/plunkconnect/coupon
 * Same HMAC auth as the webhook. Body:
 * {email, type: percent|amount|free_shipping, value, prefix, validDays,
 *  minAmount, currency}
 * -> {code, validUntil}; the code is also written to the contact as
 * data.lastCouponCode / lastCouponValidUntil via the outbox.
 */
require_once dirname(__DIR__, 2) . '/autoload.php';

use PlunkConnect\Config;
use PlunkConnect\Service\CouponService;
use PlunkConnect\Service\RequestAuth;

class PlunkconnectCouponModuleFrontController extends ModuleFrontController
{
    public function postProcess(): void
    {
        if ('POST' !== strtoupper((string) $_SERVER['REQUEST_METHOD'])) {
            $this->respond(405, ['error' => 'method_not_allowed']);
        }
        if (!Config::getBool(Config::FEAT_COUPONS)) {
            $this->respond(403, ['error' => 'feature_disabled']);
        }

        $rawBody = (string) file_get_contents('php://input');
        if (!RequestAuth::verifySignedRequest($rawBody)) {
            $this->respond(401, ['error' => 'unauthorized']);
        }
        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            $this->respond(400, ['error' => 'invalid_json']);
        }

        try {
            $result = CouponService::create($body);
        } catch (InvalidArgumentException $e) {
            $this->respond(422, ['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->respond(500, ['error' => 'internal']);
        }

        $this->respond(200, $result);
    }

    private function respond(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        exit(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
