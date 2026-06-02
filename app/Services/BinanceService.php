<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BinanceService
{
    private Client $client;

    private const BASE_URL = 'https://fapi.binance.com';

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    public function getAllTickers(): array
    {
        // Do not cache empty results — let the next call retry the API
        $key = 'binance_all_tickers';
        $cached = Cache::get($key);
        if (!empty($cached)) {
            return $cached;
        }

        try {
            $response = $this->client->get('/fapi/v1/ticker/24hr');
            $tickers = json_decode($response->getBody()->getContents(), true) ?? [];

            $result = collect($tickers)
                ->filter(fn($t) => str_ends_with($t['symbol'] ?? '', 'USDT'))
                ->sortByDesc(fn($t) => (float) ($t['quoteVolume'] ?? 0))
                ->values()
                ->toArray();

            if (!empty($result)) {
                Cache::put($key, $result, 60);
            }

            return $result;
        } catch (RequestException $e) {
            Log::error('Binance tickers failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getTopSymbolsByVolume(int $limit = 50): array
    {
        return array_column(
            array_slice($this->getAllTickers(), 0, $limit),
            'symbol'
        );
    }

    // $timeframe: '1D', '1W', '1M'
    public function getKlines(string $symbol, string $timeframe, int $limit = 250): array
    {
        $interval = match($timeframe) {
            '1D' => '1d',
            '1W' => '1w',
            '1M' => '1M',
            default => '1d',
        };

        $ttl = match($timeframe) {
            '1D' => 3600,
            '1W' => 21600,
            '1M' => 86400,
            default => 3600,
        };

        $key = "binance_klines_{$symbol}_{$interval}";
        $cached = Cache::get($key);
        if (!empty($cached)) {
            return $cached;
        }

        try {
            $response = $this->client->get('/fapi/v1/klines', [
                'query' => ['symbol' => $symbol, 'interval' => $interval, 'limit' => $limit],
            ]);

            $raw = json_decode($response->getBody()->getContents(), true) ?? [];

            $klines = array_map(fn($k) => [
                'open_time' => $k[0],
                'open'      => (float) $k[1],
                'high'      => (float) $k[2],
                'low'       => (float) $k[3],
                'close'     => (float) $k[4],
                'volume'    => (float) $k[5],
            ], $raw);

            if (!empty($klines)) {
                Cache::put($key, $klines, $ttl);
            }

            return $klines;
        } catch (RequestException $e) {
            Log::error("Binance klines failed {$symbol}/{$interval}: " . $e->getMessage());
            return [];
        }
    }
}
