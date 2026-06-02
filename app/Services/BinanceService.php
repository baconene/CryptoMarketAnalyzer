<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BinanceService
{
    // Binance has multiple regional endpoints — try each in order
    private const BASE_URLS = [
        'https://fapi.binance.com',
        'https://fapi1.binance.com',
        'https://fapi2.binance.com',
        'https://fapi3.binance.com',
    ];

    private const TIMEOUT = 15;

    private function request(string $path, array $query = []): array
    {
        foreach (self::BASE_URLS as $baseUrl) {
            try {
                $client = new Client([
                    'base_uri' => $baseUrl,
                    'timeout'  => self::TIMEOUT,
                    'headers'  => ['Accept' => 'application/json'],
                ]);

                $response = $client->get($path, $query ? ['query' => $query] : []);

                $data = json_decode($response->getBody()->getContents(), true);

                if (!empty($data)) {
                    return $data;
                }
            } catch (RequestException $e) {
                Log::warning("Binance {$baseUrl}{$path} failed: " . $e->getMessage());
                // try next base URL
            }
        }

        Log::error("Binance: all base URLs failed for {$path}");
        return [];
    }

    public function getAllTickers(): array
    {
        $key = 'binance_all_tickers';
        $cached = Cache::get($key);
        if (!empty($cached)) {
            return $cached;
        }

        $tickers = $this->request('/fapi/v1/ticker/24hr');

        if (empty($tickers)) {
            return [];
        }

        $result = collect($tickers)
            ->filter(fn($t) => str_ends_with($t['symbol'] ?? '', 'USDT'))
            ->sortByDesc(fn($t) => (float) ($t['quoteVolume'] ?? 0))
            ->values()
            ->toArray();

        if (!empty($result)) {
            Cache::put($key, $result, 60);
        }

        return $result;
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

        $raw = $this->request('/fapi/v1/klines', [
            'symbol'   => $symbol,
            'interval' => $interval,
            'limit'    => $limit,
        ]);

        if (empty($raw)) {
            return [];
        }

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
    }

    // Returns the last known error for debug output
    public function testConnectivity(): array
    {
        $results = [];
        foreach (self::BASE_URLS as $url) {
            try {
                $client = new Client(['base_uri' => $url, 'timeout' => 8]);
                $r = $client->get('/fapi/v1/ping');
                $results[$url] = $r->getStatusCode() === 200 ? 'OK' : 'HTTP ' . $r->getStatusCode();
            } catch (RequestException $e) {
                $response = $e->hasResponse() ? $e->getResponse() : null;
                $results[$url] = $response
                    ? 'HTTP ' . $response->getStatusCode() . ' — ' . substr($response->getBody(), 0, 100)
                    : $e->getMessage();
            } catch (\Exception $e) {
                $results[$url] = $e->getMessage();
            }
        }
        return $results;
    }
}
