<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TradingViewService
{
    private Client $client;

    private const SCANNER_URL = 'https://scanner.tradingview.com/crypto/scan';

    private const TIMEFRAME_MAP = [
        '1D' => '1d',
        '1W' => '1W',
        '1M' => '1M',
    ];

    private const COLUMNS = [
        'close', 'volume', 'change', 'RSI', 'RSI[1]',
        'MACD.macd', 'MACD.signal',
        'EMA20', 'EMA50', 'EMA200',
        'Recommend.All', 'Recommend.MA', 'Recommend.Other',
        'Buy', 'Sell', 'Neutral',
    ];

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; CryptoScanner/1.0)',
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Origin' => 'https://www.tradingview.com',
                'Referer' => 'https://www.tradingview.com/',
            ],
        ]);
    }

    public function scanSymbols(array $symbols, string $timeframe = '1D', string $exchange = 'BINANCE'): array
    {
        $cacheKey = "tv_scan_{$exchange}_{$timeframe}_" . md5(implode(',', $symbols));
        $cacheTtl = $this->getCacheTtl($timeframe);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($symbols, $timeframe, $exchange) {
            return $this->fetchFromTradingView($symbols, $timeframe, $exchange);
        });
    }

    private function fetchFromTradingView(array $symbols, string $timeframe, string $exchange): array
    {
        $tvTimeframe = self::TIMEFRAME_MAP[$timeframe] ?? '1d';

        $formattedSymbols = array_map(
            fn($s) => "{$exchange}:{$s}",
            $symbols
        );

        $payload = [
            'symbols' => ['tickers' => $formattedSymbols, 'query' => ['types' => []]],
            'columns' => array_map(
                fn($col) => $tvTimeframe !== '1d' ? "{$col}|{$tvTimeframe}" : $col,
                self::COLUMNS
            ),
        ];

        try {
            $response = $this->client->post(self::SCANNER_URL, [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $this->parseResponse($data, $symbols, $timeframe);
        } catch (RequestException $e) {
            Log::error("TradingView scan failed for {$exchange} {$timeframe}: " . $e->getMessage());
            return [];
        }
    }

    private function parseResponse(array $data, array $symbols, string $timeframe): array
    {
        $results = [];

        foreach ($data['data'] ?? [] as $item) {
            $values = $item['d'] ?? [];
            $ticker = $item['s'] ?? '';

            // Extract symbol from "EXCHANGE:SYMBOL" format
            $parts = explode(':', $ticker);
            $symbol = end($parts);

            if (empty($values)) {
                continue;
            }

            [$close, $volume, $change, $rsi, $rsiPrev,
             $macd, $macdSignal, $ema20, $ema50, $ema200,
             $recommendAll, $recommendMa, $recommendOther,
             $buy, $sell, $neutral] = array_pad($values, 16, null);

            $signalType = $this->mapRecommendation($recommendAll);

            $results[$symbol] = [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'price' => $close,
                'volume' => $volume,
                'change_24h' => $change,
                'rsi' => $rsi,
                'macd' => $macd,
                'macd_signal' => $macdSignal,
                'ema_20' => $ema20,
                'ema_50' => $ema50,
                'ema_200' => $ema200,
                'signal_type' => $signalType,
                'buy_indicators' => (int) $buy,
                'sell_indicators' => (int) $sell,
                'neutral_indicators' => (int) $neutral,
                'recommend_all' => $recommendAll,
            ];
        }

        return $results;
    }

    private function mapRecommendation(?float $value): string
    {
        if ($value === null) {
            return 'NEUTRAL';
        }

        if ($value >= 0.5) {
            return 'STRONG_BUY';
        } elseif ($value > 0.1) {
            return 'BUY';
        } elseif ($value <= -0.5) {
            return 'STRONG_SELL';
        } elseif ($value < -0.1) {
            return 'SELL';
        }

        return 'NEUTRAL';
    }

    private function getCacheTtl(string $timeframe): int
    {
        return match($timeframe) {
            '1D' => 3600,       // 1 hour for daily
            '1W' => 21600,      // 6 hours for weekly
            '1M' => 86400,      // 24 hours for monthly
            default => 3600,
        };
    }

    public function getTopCryptoByVolume(string $exchange = 'BINANCE', int $limit = 100): array
    {
        $cacheKey = "tv_top_crypto_{$exchange}_{$limit}";

        return Cache::remember($cacheKey, 1800, function () use ($exchange, $limit) {
            $payload = [
                'filter' => [
                    ['left' => 'exchange', 'operation' => 'equal', 'right' => $exchange],
                    ['left' => 'typespecs', 'operation' => 'has_none_of', 'right' => ['spot']],
                ],
                'options' => ['lang' => 'en'],
                'markets' => ['crypto'],
                'symbols' => ['query' => ['types' => ['futures']]],
                'columns' => ['name', 'volume24h_calc', 'close'],
                'sort' => ['sortBy' => 'volume24h_calc', 'sortOrder' => 'desc'],
                'range' => [0, $limit],
            ];

            try {
                $response = $this->client->post(self::SCANNER_URL, ['json' => $payload]);
                $data = json_decode($response->getBody()->getContents(), true);

                return array_filter(array_map(function ($item) {
                    $name = $item['d'][0] ?? null;
                    return $name ? str_replace(['USDT.P', 'USDT_UMCBL'], 'USDT', $name) : null;
                }, $data['data'] ?? []));
            } catch (RequestException $e) {
                Log::error("TradingView top crypto fetch failed: " . $e->getMessage());
                return [];
            }
        });
    }
}
