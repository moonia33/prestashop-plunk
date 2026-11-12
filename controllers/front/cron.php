<?php

/**
 * GET /module/plunkconnect/cron?token=<cron token>
 * Fallback worker trigger for hosts without CLI access. Run every minute.
 */
require_once dirname(__DIR__, 2) . '/autoload.php';

use PlunkConnect\Outbox\OutboxProcessor;
use PlunkConnect\Service\RequestAuth;

class PlunkconnectCronModuleFrontController extends ModuleFrontController
{
    public function postProcess(): void
    {
        if (!RequestAuth::checkCronToken()) {
            http_response_code(401);
            exit(json_encode(['ok' => false, 'error' => 'unauthorized']));
        }

        $processor = new OutboxProcessor();
        $stats = $processor->process(
            min(200, max(1, (int) Tools::getValue('limit', 50))),
            min(50, max(5, (int) Tools::getValue('max_seconds', 25)))
        );
        $stats['cleaned'] = $processor->cleanup(2);

        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        exit(json_encode(['ok' => true] + $stats));
    }
}
