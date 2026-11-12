<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * Minimal HTTP client for the Plunk API. Uses ext-curl, no external
 * dependencies. TLS verification is always enabled.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

namespace PlunkConnect\Api;

class PlunkClient
{
    public const CONNECT_TIMEOUT_SECONDS = 3;
    public const TOTAL_TIMEOUT_SECONDS = 10;

    /** @var string Base API URL, e.g. https://api.mk.tvparkas.lt (no trailing slash) */
    private $baseUrl;

    /** @var string Public key pk_... (used for /v1/track) */
    private $publicKey;

    /** @var string Secret key sk_... (used for /contacts etc.) */
    private $secretKey;

    public function __construct(string $baseUrl, string $publicKey = '', string $secretKey = '')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->publicKey = $publicKey;
        $this->secretKey = $secretKey;
    }

    /**
     * POST /v1/track – event + contact upsert (public key).
     *
     * @param array<string, mixed> $payload {event, email, subscribed?, data?}
     * @param string $idempotencyKey UUID from the outbox row
     */
    public function track(array $payload, string $idempotencyKey): PlunkResponse
    {
        return $this->request('POST', '/v1/track', $payload, $this->publicKey, $idempotencyKey);
    }

    /**
     * POST /contacts – contact upsert (secret key).
     *
     * @param array<string, mixed> $payload {email, data?, subscribed?}
     * @param string $idempotencyKey
     */
    public function upsertContact(array $payload, string $idempotencyKey): PlunkResponse
    {
        return $this->request('POST', '/contacts', $payload, $this->secretKey, $idempotencyKey);
    }

    /**
     * POST /contacts/lookup – bulk existence check, max 500 emails.
     *
     * @param array<int, string> $emails
     *
     * @return PlunkResponse body: {found: string[], notFound: string[]}
     */
    public function lookupContacts(array $emails): PlunkResponse
    {
        return $this->request('POST', '/contacts/lookup', ['emails' => array_values($emails)], $this->secretKey);
    }

    /**
     * GET /contacts – cursor pagination, used by the reconcile command and
     * by the GDPR delete flow (search by email).
     *
     * @param int $limit Max 100
     * @param string|null $cursor Opaque nextCursor from a previous page
     * @param array<string, mixed> $query Extra query params: search, status, sort, dir
     */
    public function listContacts(int $limit = 100, ?string $cursor = null, array $query = []): PlunkResponse
    {
        $params = array_merge(['limit' => min($limit, 100)], $query);
        if (null !== $cursor && '' !== $cursor) {
            $params['cursor'] = $cursor;
        }

        return $this->request('GET', '/contacts?' . http_build_query($params), null, $this->secretKey);
    }

    /**
     * Finds a single contact by exact email via GET /contacts?search=.
     * The search endpoint is a fuzzy match, so the result list is filtered
     * for an exact (case-insensitive) email match.
     *
     * @return array<string, mixed>|null The contact array (has 'id') or null
     */
    public function findContactByEmail(string $email): ?array
    {
        $response = $this->listContacts(100, null, ['search' => $email]);
        if (!$response->isSuccess() || !is_array($this->extractItems($response))) {
            return null;
        }

        $normalized = function_exists('mb_strtolower') ? mb_strtolower($email) : strtolower($email);
        foreach ($this->extractItems($response) as $contact) {
            $candidate = isset($contact['email']) ? (string) $contact['email'] : '';
            $candidateNorm = function_exists('mb_strtolower') ? mb_strtolower($candidate) : strtolower($candidate);
            if ($candidateNorm === $normalized) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * DELETE /contacts/:id – GDPR erasure.
     */
    public function deleteContact(string $contactId, string $idempotencyKey): PlunkResponse
    {
        return $this->request('DELETE', '/contacts/' . rawurlencode($contactId), null, $this->secretKey, $idempotencyKey);
    }

    /**
     * Cheap connectivity check used by the "Test connection" button:
     * GET /contacts?limit=1 authenticated with the secret key.
     */
    public function testConnection(): PlunkResponse
    {
        return $this->request('GET', '/contacts?limit=1', null, $this->secretKey);
    }

    /**
     * @param array<string, mixed>|string|null $body
     */
    private function request(string $method, string $path, $body, string $bearerToken, ?string $idempotencyKey = null): PlunkResponse
    {
        $ch = curl_init($this->baseUrl . $path);
        if (false === $ch) {
            return new PlunkResponse(0, null, '', null, 'curl_init failed');
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: PlunkConnect-PrestaShop/1.0',
        ];
        if ('' !== $bearerToken) {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
        }
        if (null !== $idempotencyKey && '' !== $idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER => true,
        ]);

        if (null !== $body) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (false === $json) {
                curl_close($ch);

                return new PlunkResponse(0, null, '', null, 'json_encode failed');
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $raw = curl_exec($ch);
        $retryAfter = null;

        if (false === $raw) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            return new PlunkResponse(0, null, '', null, 'curl error ' . $errno . ': ' . $error);
        }

        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerText = substr($raw, 0, $headerSize);
        $rawBody = substr($raw, $headerSize);
        curl_close($ch);

        if (429 === $statusCode && preg_match('/^Retry-After:\s*(\d+)/mi', $headerText, $m)) {
            $retryAfter = (int) $m[1];
        }

        $decoded = json_decode($rawBody, true);

        return new PlunkResponse($statusCode, is_array($decoded) ? $decoded : null, $rawBody, $retryAfter);
    }

    /**
     * Contact list responses may be shaped {contacts: [...]} or {data: [...]}
     * depending on the Plunk version; normalize to a plain array.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function extractItems(PlunkResponse $response): ?array
    {
        if (!is_array($response->body)) {
            return null;
        }
        foreach (['contacts', 'data', 'items', 'results'] as $key) {
            if (isset($response->body[$key]) && is_array($response->body[$key])) {
                return $response->body[$key];
            }
        }

        return is_array($response->body) && array_keys($response->body) === range(0, count($response->body) - 1)
            ? $response->body
            : null;
    }
}
