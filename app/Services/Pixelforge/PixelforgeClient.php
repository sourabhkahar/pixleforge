<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP wrapper around the Pixelforge v1 API. Returns decoded JSON arrays
 * and turns every failure into a classified PixelforgeApiException.
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout = 15,
    ) {}

    /**
     * POST /v1/generations — returns the generation object (status "queued").
     *
     * @param  array{prompt: string, size: string, quality: string, reference_images?: list<string>, metadata?: array<string,string>}  $params
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $params))->json();
    }

    /** GET /v1/generations/{id} */
    public function getGeneration(string $providerId): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/generations/'.rawurlencode($providerId)))->json();
    }

    /** GET /v1/pricing (no auth required) */
    public function pricing(): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/pricing'), authenticated: false)->json();
    }

    /** GET /v1/usage?start_date=&end_date= (UTC, inclusive, max 92 days) */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]))->json();
    }

    private function http(bool $authenticated): PendingRequest
    {
        $http = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout(5);

        if ($authenticated) {
            if (empty($this->apiKey)) {
                throw new PixelforgeApiException('PIXELFORGE_API_KEY is not set.', 'invalid_api_key', 401);
            }
            $http = $http->withToken($this->apiKey);
        }

        return $http;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call, bool $authenticated = true): Response
    {
        try {
            $response = $call($this->http($authenticated));
        } catch (ConnectionException $e) {
            throw new PixelforgeApiException('Could not reach Pixelforge: '.$e->getMessage(), previous: $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $error = $response->json('error') ?? [];
        $retryAfter = $response->header('Retry-After');

        throw new PixelforgeApiException(
            message: is_array($error) && isset($error['message']) ? (string) $error['message'] : "HTTP {$response->status()}",
            errorCode: is_array($error) ? ($error['code'] ?? null) : null,
            httpStatus: $response->status(),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }
}
