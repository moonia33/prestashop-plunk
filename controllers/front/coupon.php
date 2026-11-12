<?php

/**
 * POST /module/plunkconnect/coupon
 * Auth: `Authorization: Bearer <coupon secret>` (Config::COUPON_SECRET).
 * Body: {email, rule, cartId?|executionId?}
 *  - `rule` selects a coupon rule from the module config (type/value/
 *    validDays/minAmount/prefix/excludeSpecial are NOT trusted from the
 *    request, they come from COUPON_RULES settings).
 * Idempotent on email+rule+cartId/executionId. Rate-limited per email+rule
 * over a rolling 30-day window.
 * -> {code, validUntil, value, type, minAmount}.
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
        if (!RequestAuth::checkBearer(Config::COUPON_SECRET)) {
            $this->respond(401, ['error' => 'unauthorized']);
        }
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $this->respond(400, ['error' => 'invalid_json']);
        }

        $rule = (string) ($body['rule'] ?? '');
        $email = (string) ($body['email'] ?? '');
        $cartId = (int) ($body['cartId'] ?? $body['cart_id'] ?? 0);
        $executionId = substr((string) ($body['executionId'] ?? $body['execution_id'] ?? ''), 0, 64);

        try {
            $result = CouponService::create($rule, $email, $cartId, $executionId);
        } catch (InvalidArgumentException $e) {
            $this->respond(422, ['error' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            if ('rate_limited' === $e->getMessage()) {
                $this->respond(429, ['error' => 'rate_limited']);
            }
            $this->respond(500, ['error' => 'internal']);
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
