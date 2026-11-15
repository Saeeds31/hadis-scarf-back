<?php
// Modules/Anipo/Services/AnipoClient.php

namespace Modules\Anipo\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnipoClient
{
    protected string $baseUrl;
    protected string $token;
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('anipo.base_url'), '/');
        $this->token   = (string) config('anipo.token');
        $this->timeout = (int) config('anipo.timeout', 20);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }

    public function ping(): array
    {
        return $this->safe(fn() => $this->client()->get('/ping'));
    }

    public function provinces(): array
    {
        return $this->safe(fn() => $this->client()->get('/provinces'));
    }

    public function cities(string $province): array
    {
        return $this->safe(fn() => $this->client()->get('/cities', ['province' => $province]));
    }

    public function upsertOrder(array $payload): array
    {
        return $this->safe(fn() => $this->client()->post('/orders', $payload));
    }

    public function getOrder(string $code): array
    {
        return $this->safe(fn() => $this->client()->get('/orders/' . rawurlencode($code)));
    }

    public function getBarcode(string $code): array
    {
        return $this->safe(fn() => $this->client()->get('/orders/' . rawurlencode($code) . '/barcode'));
    }

    public function getBarcodes(array $codes): array
    {
        return $this->safe(fn() => $this->client()->post('/orders/barcodes', ['codes' => $codes]));
    }

    public function updateStatus(string $code, string $status): array
    {
        return $this->safe(fn() => $this->client()->put(
            '/orders/' . rawurlencode($code) . '/status',
            ['order_status' => $status]
        ));
    }

    protected function safe(callable $fn): array
    {
        try {
            $res = $fn();
            $json = $res->json();

            if (!is_array($json)) {
                Log::channel('daily')->warning('Anipo non-json response', [
                    'status' => $res->status(),
                    'body'   => $res->body(),
                ]);
                return [];
            }

            return $json;
        } catch (\Throwable $e) {
            Log::channel('daily')->error('Anipo request failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
