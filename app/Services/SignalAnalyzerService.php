<?php

namespace App\Services;

use App\Models\MarketSignal;
use App\Models\User;
use App\Models\Watchlist;
use App\Notifications\MarketSignalNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SignalAnalyzerService
{
    private const TIMEFRAMES = ['1D', '1W', '1M'];

    private const TIMEFRAME_NOTIFY_FIELD = [
        '1D' => 'notify_daily',
        '1W' => 'notify_weekly',
        '1M' => 'notify_monthly',
    ];

    public function __construct(
        private TradingViewService $tradingView,
        private BinanceService $binance,
        private BitgetService $bitget,
    ) {}

    public function scanExchange(string $exchange, string $timeframe, int $topN = 50): array
    {
        $symbols = match($exchange) {
            'binance' => $this->binance->getTopSymbolsByVolume($topN),
            'bitget'  => $this->bitget->getTopSymbolsByVolume($topN),
            default   => [],
        };

        if (empty($symbols)) {
            Log::warning("No symbols found for {$exchange}");
            return [];
        }

        $tvExchange = match($exchange) {
            'binance' => 'BINANCE',
            'bitget'  => 'BITGET',
            default   => strtoupper($exchange),
        };

        $scanResults = $this->tradingView->scanSymbols($symbols, $timeframe, $tvExchange);

        $signals = [];

        foreach ($scanResults as $symbol => $data) {
            if ($data['signal_type'] === 'NEUTRAL') {
                continue;
            }

            $signal = $this->saveSignal($exchange, $symbol, $timeframe, $data);

            if ($signal) {
                $signals[] = $signal;
            }
        }

        Log::info("Scanned {$exchange} {$timeframe}: found " . count($signals) . " actionable signals from " . count($scanResults) . " symbols");

        return $signals;
    }

    public function scanAll(string $timeframe): array
    {
        $allSignals = [];

        foreach (['binance', 'bitget'] as $exchange) {
            $signals = $this->scanExchange($exchange, $timeframe);
            $allSignals = array_merge($allSignals, $signals);
        }

        $this->notifyUsers($allSignals, $timeframe);

        return $allSignals;
    }

    private function saveSignal(string $exchange, string $symbol, string $timeframe, array $data): ?MarketSignal
    {
        try {
            // Check if we already have a recent signal for this combo (within the scan window)
            $windowMinutes = match($timeframe) {
                '1D' => 60,
                '1W' => 360,
                '1M' => 1440,
                default => 60,
            };

            $existing = MarketSignal::where('exchange', $exchange)
                ->where('symbol', $symbol)
                ->where('timeframe', $timeframe)
                ->where('created_at', '>=', now()->subMinutes($windowMinutes))
                ->latest()
                ->first();

            if ($existing && $existing->signal_type === $data['signal_type']) {
                return null;
            }

            return MarketSignal::create([
                'exchange' => $exchange,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'signal_type' => $data['signal_type'],
                'price' => $data['price'],
                'rsi' => $data['rsi'],
                'macd' => $data['macd'],
                'macd_signal' => $data['macd_signal'],
                'ema_20' => $data['ema_20'],
                'ema_50' => $data['ema_50'],
                'ema_200' => $data['ema_200'],
                'volume' => $data['volume'],
                'change_24h' => $data['change_24h'],
                'buy_indicators' => $data['buy_indicators'],
                'sell_indicators' => $data['sell_indicators'],
                'neutral_indicators' => $data['neutral_indicators'],
                'raw_data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to save signal for {$exchange}/{$symbol}/{$timeframe}: " . $e->getMessage());
            return null;
        }
    }

    private function notifyUsers(array $signals, string $timeframe): void
    {
        if (empty($signals)) {
            return;
        }

        $notifyField = self::TIMEFRAME_NOTIFY_FIELD[$timeframe] ?? 'notify_daily';

        $users = User::whereHas('watchlists', function ($q) use ($notifyField) {
            $q->where('is_active', true)->where($notifyField, true);
        })->with(['watchlists' => function ($q) use ($notifyField) {
            $q->where('is_active', true)->where($notifyField, true);
        }])->get();

        foreach ($users as $user) {
            $watchedSymbols = $user->watchlists->pluck('symbol')->map(fn($s) => strtoupper($s))->toArray();
            $watchedExchanges = $user->watchlists->pluck('exchange')->toArray();

            $userSignals = array_filter($signals, function ($signal) use ($watchedSymbols, $watchedExchanges) {
                $symbolMatch = in_array(strtoupper($signal->symbol), $watchedSymbols);
                $exchangeMatch = in_array('both', $watchedExchanges) || in_array($signal->exchange, $watchedExchanges);
                return $symbolMatch && $exchangeMatch;
            });

            if (!empty($userSignals)) {
                try {
                    $user->notify(new MarketSignalNotification(array_values($userSignals), $timeframe));
                    foreach ($userSignals as $signal) {
                        $signal->update(['notification_sent' => true]);
                    }
                } catch (\Exception $e) {
                    Log::error("Failed to notify user {$user->id}: " . $e->getMessage());
                }
            }
        }
    }

    public function getSignalStrength(MarketSignal $signal): string
    {
        $total = $signal->buy_indicators + $signal->sell_indicators + $signal->neutral_indicators;
        if ($total === 0) {
            return 'Unknown';
        }

        $dominantCount = max($signal->buy_indicators, $signal->sell_indicators);
        $percentage = ($dominantCount / $total) * 100;

        if ($percentage >= 75) return 'Very Strong';
        if ($percentage >= 60) return 'Strong';
        if ($percentage >= 50) return 'Moderate';
        return 'Weak';
    }
}
