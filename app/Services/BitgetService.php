<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BitgetService
{
    private Client $client;

    private const BASE_URL = 'https://api.bitget.com';

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    public function getFuturesSymbols(string $productType = 'umcbl'): array
    {
        return Cache::remember("bitget_futures_symbols_{$productType}", 3600, function () use ($productType) {
            try {
                $response = $this->client->get('/api/mix/v1/market/contracts', [
                    'query' => ['productType' => $productType],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                $symbols = array_map(
                    fn($s) => $s['symbolName'] ?? null,
                    $data['data'] ?? []
                );

                return array_values(array_filter($symbols, fn($s) => $s && str_contains($s, 'USDT')));
            } catch (RequestException $e) {
                Log::error('Bitget symbol fetch failed: ' . $e->getMessage());
                return $this->getDefaultSymbols();
            }
        });
    }

    public function getAllTickers(string $productType = 'umcbl'): array
    {
        return Cache::remember("bitget_all_tickers_{$productType}", 60, function () use ($productType) {
            try {
                $response = $this->client->get('/api/mix/v1/market/tickers', [
                    'query' => ['productType' => $productType],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                return collect($data['data'] ?? [])
                    ->filter(fn($t) => str_contains($t['symbol'] ?? '', 'USDT'))
                    ->sortByDesc(fn($t) => (float) ($t['usdtVolume'] ?? $t['baseVolume'] ?? 0))
                    ->values()
                    ->toArray();
            } catch (RequestException $e) {
                Log::error('Bitget all tickers fetch failed: ' . $e->getMessage());
                return [];
            }
        });
    }

    public function getTopSymbolsByVolume(int $limit = 50, string $productType = 'umcbl'): array
    {
        $tickers = $this->getAllTickers($productType);

        $symbols = array_column(array_slice($tickers, 0, $limit), 'symbol');

        // Bitget symbols are like BTCUSDT_UMCBL, normalize to just BTCUSDT
        return array_map(
            fn($s) => str_replace(['_UMCBL', '_DMCBL', '_CMCBL'], '', $s),
            $symbols
        );
    }

    public function getTicker(string $symbol, string $productType = 'umcbl'): array
    {
        $normalizedSymbol = $symbol . '_UMCBL';

        try {
            $response = $this->client->get('/api/mix/v1/market/ticker', [
                'query' => ['symbol' => $normalizedSymbol],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['data'] ?? [];
        } catch (RequestException $e) {
            Log::error("Bitget ticker fetch failed for {$symbol}: " . $e->getMessage());
            return [];
        }
    }

    public function getKlines(string $symbol, string $granularity, int $limit = 100): array
    {
        $normalizedSymbol = str_contains($symbol, '_UMCBL') ? $symbol : $symbol . '_UMCBL';

        try {
            $response = $this->client->get('/api/mix/v1/market/candles', [
                'query' => [
                    'symbol' => $normalizedSymbol,
                    'granularity' => $granularity,
                    'limit' => $limit,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return array_map(fn($k) => [
                'open_time' => $k[0],
                'open' => (float) $k[1],
                'high' => (float) $k[2],
                'low' => (float) $k[3],
                'close' => (float) $k[4],
                'volume' => (float) $k[5],
            ], $data['data'] ?? []);
        } catch (RequestException $e) {
            Log::error("Bitget klines fetch failed for {$symbol}: " . $e->getMessage());
            return [];
        }
    }

    // Map interval to TradingView exchange format for Bitget
    public function getTradingViewExchange(): string
    {
        return 'BITGET';
    }

    private function getDefaultSymbols(): array
    {
        return [
            'BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'XRPUSDT', 'SOLUSDT',
            'ADAUSDT', 'DOGEUSDT', 'AVAXUSDT', 'DOTUSDT', 'MATICUSDT',
        ];
    }
}
