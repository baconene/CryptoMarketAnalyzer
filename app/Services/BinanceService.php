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

    public function getFuturesSymbols(): array
    {
        return Cache::remember('binance_futures_symbols', 3600, function () {
            try {
                $response = $this->client->get('/fapi/v1/exchangeInfo');
                $data = json_decode($response->getBody()->getContents(), true);

                return array_values(array_filter(
                    array_map(fn($s) => $s['symbol'] ?? null, $data['symbols'] ?? []),
                    fn($s) => $s && str_ends_with($s, 'USDT')
                ));
            } catch (RequestException $e) {
                Log::error('Binance symbol fetch failed: ' . $e->getMessage());
                return $this->getDefaultSymbols();
            }
        });
    }

    public function getTicker(string $symbol): array
    {
        try {
            $response = $this->client->get('/fapi/v1/ticker/24hr', [
                'query' => ['symbol' => $symbol],
            ]);
            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            Log::error("Binance ticker fetch failed for {$symbol}: " . $e->getMessage());
            return [];
        }
    }

    public function getAllTickers(): array
    {
        return Cache::remember('binance_all_tickers', 60, function () {
            try {
                $response = $this->client->get('/fapi/v1/ticker/24hr');
                $tickers = json_decode($response->getBody()->getContents(), true) ?? [];

                return collect($tickers)
                    ->filter(fn($t) => str_ends_with($t['symbol'], 'USDT'))
                    ->sortByDesc(fn($t) => (float) $t['quoteVolume'])
                    ->values()
                    ->toArray();
            } catch (RequestException $e) {
                Log::error('Binance all tickers fetch failed: ' . $e->getMessage());
                return [];
            }
        });
    }

    public function getTopSymbolsByVolume(int $limit = 50): array
    {
        $tickers = $this->getAllTickers();

        return array_column(
            array_slice($tickers, 0, $limit),
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

        $cacheKey = "binance_klines_{$symbol}_{$interval}_{$limit}";
        $ttl = match($timeframe) {
            '1D' => 3600,
            '1W' => 21600,
            '1M' => 86400,
            default => 3600,
        };

        return Cache::remember($cacheKey, $ttl, function () use ($symbol, $interval, $limit) {
            try {
                $response = $this->client->get('/fapi/v1/klines', [
                    'query' => compact('symbol', 'interval', 'limit'),
                ]);

                $klines = json_decode($response->getBody()->getContents(), true) ?? [];

                return array_map(fn($k) => [
                    'open_time' => $k[0],
                    'open' => (float) $k[1],
                    'high' => (float) $k[2],
                    'low' => (float) $k[3],
                    'close' => (float) $k[4],
                    'volume' => (float) $k[5],
                    'close_time' => $k[6],
                ], $klines);
            } catch (RequestException $e) {
                Log::error("Binance klines fetch failed for {$symbol}/{$interval}: " . $e->getMessage());
                return [];
            }
        });
    }

    private function getDefaultSymbols(): array
    {
        return [
            'BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'XRPUSDT', 'SOLUSDT',
            'ADAUSDT', 'DOGEUSDT', 'AVAXUSDT', 'DOTUSDT', 'MATICUSDT',
            'LTCUSDT', 'LINKUSDT', 'UNIUSDT', 'ATOMUSDT', 'ETCUSDT',
        ];
    }
}
