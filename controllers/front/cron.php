<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * GET /module/plunkconnect/cron?token=<cron token>[&limit=200][&shop=1]
 *
 * HTTP fallback for the outbox worker, for hosts without CLI access.
 * Schedule: every minute. The worker self-limits to ~50 s and ~20 req/s.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use PlunkConnect\Config\ModuleConfiguration;

class PlunkconnectCronModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $auth = false;
    /** @var bool */
    public $guestAllowed = true;

    public function postProcess(): void
    {
        $module = $this->module;
        if (!$module instanceof Plunkconnect) {
            $this->jsonResponse(500, ['error' => 'module unavailable']);
        }
        $config = $module->getConfiguration();
        $idShop = (int) $this->context->shop->id;

        $expected = $config->get(ModuleConfiguration::CRON_TOKEN, $idShop);
        $provided = (string) Tools::getValue('token');

        if ('' === $expected || !hash_equals($expected, $provided)) {
            $this->jsonResponse(401, ['error' => 'unauthorized']);
        }

        $limit = min(max((int) Tools::getValue('limit', 200), 1), 200);
        $shop = Tools::getValue('shop');
        $idShopFilter = false !== $shop && '' !== $shop ? (int) $shop : null;

        // Web workers may kill long requests; keep the run short.
        @set_time_limit(70);
        $stats = $module->getOutboxProcessor()->process($limit, 45, $idShopFilter);

        // Occasional housekeeping (~1 run in 60 ≈ hourly at 1/min cron).
        $pruned = 0;
        if (1 === random_int(1, 60)) {
            $pruned = $module->getOutboxProcessor()->prune(7);
        }

        $this->jsonResponse(200, ['success' => true, 'stats' => $stats, 'pruned' => $pruned]);
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
