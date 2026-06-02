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

    // v1 granularity strings
    private const GRANULARITY = [
        '1D' => '1D',
        '1W' => '1W',
        '1M' => '1M',
    ];

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
        $key = 'bitget_all_tickers';
        $cached = Cache::get($key);
        if (!empty($cached)) {
            return $cached;
        }

        try {
            // v1 tickers — returns symbols like BTCUSDT_UMCBL
            $response = $this->client->get('/api/mix/v1/market/tickers', [
                'query' => ['productType' => 'umcbl'],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            $result = collect($data['data'] ?? [])
                ->filter(fn($t) => str_contains($t['symbol'] ?? '', 'USDT'))
                ->sortByDesc(fn($t) => (float) ($t['usdtVolume'] ?? $t['baseVolume'] ?? 0))
                ->values()
                ->toArray();

            if (!empty($result)) {
                Cache::put($key, $result, 60);
            }

            return $result;
        } catch (RequestException $e) {
            Log::error('Bitget tickers failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getTopSymbolsByVolume(int $limit = 50): array
    {
        $symbols = array_column(
            array_slice($this->getAllTickers(), 0, $limit),
            'symbol'
        );

        // Strip exchange suffix: BTCUSDT_UMCBL → BTCUSDT
        return array_map(
            fn($s) => preg_replace('/_[A-Z]+$/', '', $s),
            $symbols
        );
    }

    // $timeframe: '1D', '1W', '1M'
    // Uses v1 API consistently with _UMCBL symbol format
    public function getKlines(string $symbol, string $timeframe, int $limit = 200): array
    {
        $granularity = self::GRANULARITY[$timeframe] ?? '1D';

        // v1 requires the _UMCBL suffix
        $apiSymbol = str_contains($symbol, '_UMCBL') ? $symbol : $symbol . '_UMCBL';

        $ttl = match($timeframe) {
            '1D' => 3600,
            '1W' => 21600,
            '1M' => 86400,
            default => 3600,
        };

        $key = "bitget_klines_{$symbol}_{$granularity}";
        $cached = Cache::get($key);
        if (!empty($cached)) {
            return $cached;
        }

        try {
            $response = $this->client->get('/api/mix/v1/market/candles', [
                'query' => [
                    'symbol'      => $apiSymbol,
                    'granularity' => $granularity,
                    'limit'       => $limit,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // v1 response: {code, data: [[ts, open, high, low, close, vol, quoteVol], ...]}
            $rows = $data['data'] ?? [];

            // Some versions return the array directly (not nested)
            if (!empty($rows) && !is_array($rows[0])) {
                $rows = $data;
            }

            $klines = array_map(fn($k) => [
                'open_time' => (int) $k[0],
                'open'      => (float) $k[1],
                'high'      => (float) $k[2],
                'low'       => (float) $k[3],
                'close'     => (float) $k[4],
                'volume'    => (float) $k[5],
            ], $rows);

            if (!empty($klines)) {
                Cache::put($key, $klines, $ttl);
            }

            return $klines;
        } catch (RequestException $e) {
            Log::error("Bitget klines failed {$apiSymbol}/{$granularity}: " . $e->getMessage());
            return [];
        }
    }
}
