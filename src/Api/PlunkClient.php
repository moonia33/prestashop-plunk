<?php

/**
 * Thin curl client for the self-hosted Plunk API.
 *
 * Only used by the outbox worker and console commands - never called
 * synchronously in a shopper-facing request.
 */

namespace PlunkConnect\Api;

use PlunkConnect\Config;

final class PlunkClient
{
    public const CONNECT_TIMEOUT = 3;
    public const TOTAL_TIMEOUT = 10;

    private string $baseUrl;
    private ?string $publicKey;
    private ?string $secretKey;

    public function __construct(?int $idShop = null)
    {
        $this->baseUrl = rtrim((string) Config::get(Config::API_URL, '', $idShop), '/');
        $this->publicKey = (string) Config::get(Config::PUBLIC_KEY, '', $idShop);
        $this->secretKey = (string) Config::get(Config::SECRET_KEY, '', $idShop);
    }

    /**
     * POST /v1/track - event + contact upsert, public key.
     */
    public function track(array $body, string $idempotencyKey): array
    {
        return $this->request('POST', '/v1/track', $this->publicKey, $body, $idempotencyKey);
    }

    /**
     * PUT /catalog/products - full product docs, secret key.
     *
     * @param array<int, array<string, mixed>> $products max 500 per call
     */
    public function catalogUpsert(array $products, string $idempotencyKey): array
    {
        return $this->request('PUT', '/catalog/products', $this->secretKey, ['products' => $products], $idempotencyKey);
    }

    /**
     * PATCH /catalog/products - price/stock only, secret key.
     *
     * @param array<int, array<string, mixed>> $products max 1000 per call
     */
    public function catalogPatch(array $products, string $idempotencyKey): array
    {
        return $this->request('PATCH', '/catalog/products', $this->secretKey, ['products' => $products], $idempotencyKey);
    }

    /**
     * POST /catalog/products/delete - remove catalog ids, secret key.
     *
     * @param string[] $ids max 1000 per call
     */
    public function catalogDelete(array $ids, string $idempotencyKey): array
    {
        return $this->request('POST', '/catalog/products/delete', $this->secretKey, ['ids' => $ids], $idempotencyKey);
    }

    /**
     * POST /contacts - contact upsert without an event, secret key.
     */
    public function upsertContact(array $body, string $idempotencyKey): array
    {
        return $this->request('POST', '/contacts', $this->secretKey, $body, $idempotencyKey);
    }

    /**
     * POST /contacts/lookup - find a contact by email. Returns ['id'=>..] or null.
     */
    public function lookupContact(string $email): ?array
    {
        $response = $this->lookupContactResponse($email);
        if (($response['status'] ?? 0) === 200 && is_array($response['body'])) {
            return $response['body'];
        }

        return null;
    }

    /**
     * Raw lookup response (with status) so callers can tell "not found"
     * apart from "lookup failed".
     */
    public function lookupContactResponse(string $email): array
    {
        return $this->request('POST', '/contacts/lookup', $this->secretKey, ['email' => $email]);
    }

    /**
     * DELETE /contacts/:id - GDPR erasure, secret key.
     */
    public function deleteContact(string $contactId): array
    {
        return $this->request('DELETE', '/contacts/' . rawurlencode($contactId), $this->secretKey, null);
    }

    /**
     * GET /contacts - cursor-paginated listing used by the reconcile command.
     */
    public function listContacts(?string $cursor = null, int $limit = 250): array
    {
        $query = '?limit=' . $limit . ($cursor ? '&cursor=' . rawurlencode($cursor) : '');

        return $this->request('GET', '/contacts' . $query, $this->secretKey, null);
    }

    /**
     * GET /contacts?search=<email> - used by the double-opt-in guard in
     * the worker (spec 7.2) to check whether the contact already is
     * subscribed before sending subscribed:false.
     */
    public function searchContactResponse(string $email): array
    {
        return $this->request(
            'GET',
            '/contacts?limit=5&search=' . rawurlencode($email),
            $this->secretKey,
            null
        );
    }

    /**
     * Low-level request helper.
     *
     * @return array{status:int,body:?array,retryAfter:?int,error:?string,raw:string}
     */
    public function request(string $method, string $path, ?string $bearer, ?array $body = null, ?string $idempotencyKey = null): array
    {
        if ('' === $this->baseUrl || !$bearer) {
            return ['status' => 0, 'body' => null, 'retryAfter' => null, 'error' => 'not_configured', 'raw' => ''];
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $bearer,
        ];
        if (null !== $body) {
            $headers[] = 'Content-Type: application/json';
        }
        if (null !== $idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if (null !== $body) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $raw = curl_exec($ch);
        $error = null;
        $status = 0;
        $headerSize = 0;
        if (false === $raw) {
            $error = 'curl: ' . curl_error($ch);
        } else {
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        }
        curl_close($ch);

        $responseHeaders = false !== $raw ? substr($raw, 0, $headerSize) : '';
        $rawBody = false !== $raw ? substr($raw, $headerSize) : '';
        $decoded = json_decode((string) $rawBody, true);
        $retryAfter = null;
        if (preg_match('/^Retry-After:\s*(\d+)/mi', $responseHeaders, $m)) {
            $retryAfter = (int) $m[1];
        }

        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : null,
            'retryAfter' => $retryAfter,
            'error' => $error,
            'raw' => is_string($rawBody) ? $rawBody : '',
        ];
    }

    /**
     * Admin "test connection" action. Checks TLS reachability only - no
     * contact data is sent.
     */
    public function ping(): array
    {
        if ('' === $this->baseUrl) {
            return ['ok' => false, 'message' => 'API URL not configured'];
        }
        $result = $this->request('GET', '/', $this->publicKey ?: 'ping', null);

        return [
            'ok' => $result['status'] > 0,
            'message' => $result['status'] > 0
                ? 'HTTP ' . $result['status']
                : ($result['error'] ?: 'unreachable'),
        ];
    }
}
