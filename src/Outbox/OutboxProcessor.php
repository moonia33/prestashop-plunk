<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * Drains the outbox: claims batches, calls the Plunk API, applies retry /
 * dead-letter / rate-limit rules. Runs from bin/console, the cron front
 * controller or actionCronJob.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

namespace PlunkConnect\Outbox;

use PlunkConnect\Api\PlunkClient;
use PlunkConnect\Api\PlunkResponse;
use PlunkConnect\Config\ModuleConfiguration;

class OutboxProcessor
{
    /** Max wall-clock seconds per run. */
    public const MAX_RUNTIME_SECONDS = 50;

    /** Max requests per second to Plunk. */
    public const MAX_REQUESTS_PER_SECOND = 20;

    /** Outbox rows processed per run. */
    public const MAX_MESSAGES_PER_RUN = 200;

    /** Claim chunk size. */
    private const CLAIM_CHUNK = 50;

    /** @var ModuleConfiguration */
    private $config;

    /** @var OutboxRepository */
    private $repository;

    /** @var RetryPolicy */
    private $retryPolicy;

    /** @var callable|null PSR-3-ish logger fn(string, string) */
    private $logger;

    public function __construct(
        ModuleConfiguration $config,
        ?OutboxRepository $repository = null,
        ?RetryPolicy $retryPolicy = null,
        ?callable $logger = null,
    ) {
        $this->config = $config;
        $this->repository = $repository ?: new OutboxRepository();
        $this->retryPolicy = $retryPolicy ?: new RetryPolicy();
        $this->logger = $logger;
    }

    /**
     * Processes up to $maxMessages outbox rows within the time budget.
     *
     * @return array{processed:int, sent:int, retried:int, dead:int}
     */
    public function process(
        int $maxMessages = self::MAX_MESSAGES_PER_RUN,
        int $maxSeconds = self::MAX_RUNTIME_SECONDS,
        ?int $idShop = null,
    ): array {
        $stats = ['processed' => 0, 'sent' => 0, 'retried' => 0, 'dead' => 0];
        $startedAt = microtime(true);
        $locker = gethostname() . '-' . getmypid() . '-' . substr(bin2hex(random_bytes(6)), 0, 12);

        $this->repository->releaseStaleProcessing();
        $this->repository->requeueDueFailures();

        $minInterval = 1.0 / self::MAX_REQUESTS_PER_SECOND;
        $lastRequestAt = 0.0;

        while ($stats['processed'] < $maxMessages
            && (microtime(true) - $startedAt) < $maxSeconds
        ) {
            $batch = $this->repository->claimBatch(
                min(self::CLAIM_CHUNK, $maxMessages - $stats['processed']),
                $locker,
                $idShop
            );

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $row) {
                if ($stats['processed'] >= $maxMessages
                    || (microtime(true) - $startedAt) >= $maxSeconds
                ) {
                    // Give unprocessed rows of this chunk back.
                    $this->releaseRow((int) $row['id_outbox']);
                    continue;
                }

                // Rate limiting: sleep before the request if needed.
                $elapsed = microtime(true) - $lastRequestAt;
                if ($elapsed < $minInterval) {
                    usleep((int) (($minInterval - $elapsed) * 1000000));
                }
                $lastRequestAt = microtime(true);

                $this->processRow($row, (int) $row['id_shop'], $stats);
                ++$stats['processed'];
            }
        }

        return $stats;
    }

    /**
     * Deletes 'sent' rows older than 7 days. Called periodically by cron.
     */
    public function prune(int $days = 7): int
    {
        return $this->repository->pruneSent($days);
    }

    /**
     * @param array<string, mixed> $row
     * @param array{processed:int, sent:int, retried:int, dead:int} $stats
     */
    private function processRow(array $row, int $idShop, array &$stats): void
    {
        $id = (int) $row['id_outbox'];
        $endpoint = (string) $row['endpoint'];
        $uuid = (string) $row['uuid'];
        $attempts = (int) $row['attempts'] + 1;

        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload)) {
            $this->repository->markFailed($id, 0, 'invalid JSON payload', true);
            ++$stats['dead'];

            return;
        }

        if (!$this->config->isConfigured($idShop)) {
            // Keep for later: shop keys not set yet. Retry slowly.
            $this->repository->markFailed($id, 3600, 'module not configured for shop', false);
            ++$stats['retried'];

            return;
        }

        $client = $this->config->createClient($idShop);

        try {
            $response = $this->dispatch($client, $endpoint, $payload, $uuid);
        } catch (\Throwable $e) {
            $response = new PlunkResponse(0, null, '', null, $e->getMessage());
        }

        if ($response->isSuccess() || $response->isIdempotencyConflict()) {
            $this->repository->markSent($id);
            ++$stats['sent'];

            return;
        }

        if ($response->isPermanentError()) {
            $this->repository->markFailed($id, 0, $this->summarizeError($response), true);
            ++$stats['dead'];
            $this->log('error', sprintf('Outbox #%d dead (HTTP %d): %s', $id, $response->statusCode, $this->summarizeError($response)));

            return;
        }

        // Retryable: network error, 5xx, 408, 429.
        if (!$this->retryPolicy->shouldRetry($attempts)) {
            $this->repository->markFailed($id, 0, 'max attempts reached: ' . $this->summarizeError($response), true);
            ++$stats['dead'];

            return;
        }

        $delay = $response->isRateLimited() && null !== $response->retryAfter
            ? max($response->retryAfter, 1)
            : $this->retryPolicy->delayForAttempt($attempts);

        $this->repository->markFailed($id, $delay, $this->summarizeError($response), false);
        ++$stats['retried'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatch(PlunkClient $client, string $endpoint, array $payload, string $uuid): PlunkResponse
    {
        switch ($endpoint) {
            case OutboxRepository::ENDPOINT_TRACK:
                return $client->track($payload, $uuid);

            case OutboxRepository::ENDPOINT_CONTACTS:
                return $client->upsertContact($payload, $uuid);

            case OutboxRepository::ENDPOINT_DELETE:
                return $this->dispatchDelete($client, $payload, $uuid);
        }

        return new PlunkResponse(0, null, '', null, 'unknown endpoint: ' . $endpoint);
    }

    /**
     * GDPR delete: resolve the contact id by email, then DELETE it.
     * A missing contact counts as success (already gone).
     *
     * @param array<string, mixed> $payload
     */
    private function dispatchDelete(PlunkClient $client, array $payload, string $uuid): PlunkResponse
    {
        $email = isset($payload['email']) ? (string) $payload['email'] : '';
        if ('' === $email) {
            return new PlunkResponse(400, null, '', null, 'missing email');
        }

        $contact = $client->findContactByEmail($email);
        if (null === $contact) {
            return new PlunkResponse(204, null, '');
        }

        $id = isset($contact['id']) ? (string) $contact['id'] : '';
        if ('' === $id) {
            return new PlunkResponse(0, null, '', null, 'contact without id');
        }

        return $client->deleteContact($id, $uuid);
    }

    private function releaseRow(int $idOutbox): void
    {
        \Db::getInstance()->update(
            OutboxRepository::TABLE,
            [
                'status' => \pSQL(OutboxRepository::STATUS_PENDING),
                'locked_by' => ['type' => 'sql', 'value' => 'NULL'],
            ],
            '`id_outbox` = ' . (int) $idOutbox . ' AND `status` = \'' . \pSQL(OutboxRepository::STATUS_PROCESSING) . '\''
        );
    }

    /**
     * Error summary without PII: HTTP status + short body excerpt (Plunk
     * error bodies contain codes, not contact data).
     */
    private function summarizeError(PlunkResponse $response): string
    {
        if (null !== $response->transportError) {
            return mb_substr($response->transportError, 0, 200);
        }

        $summary = 'HTTP ' . $response->statusCode;
        if (is_array($response->body)) {
            foreach (['error', 'message', 'code'] as $key) {
                if (isset($response->body[$key]) && is_scalar($response->body[$key])) {
                    $summary .= ' ' . $response->body[$key];
                    break;
                }
            }
        }

        return mb_substr($summary, 0, 300);
    }

    private function log(string $level, string $message): void
    {
        if (null !== $this->logger) {
            call_user_func($this->logger, $level, $message);
        }
    }
}
