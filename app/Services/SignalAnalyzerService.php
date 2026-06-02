<?php

namespace App\Services;

use App\Models\MarketSignal;
use App\Models\User;
use App\Notifications\MarketSignalNotification;
use Illuminate\Support\Facades\Log;

class SignalAnalyzerService
{
    private const TIMEFRAME_NOTIFY_FIELD = [
        '1D' => 'notify_daily',
        '1W' => 'notify_weekly',
        '1M' => 'notify_monthly',
    ];

    private const SCAN_WINDOW_MINUTES = [
        '1D' => 60,
        '1W' => 360,
        '1M' => 1440,
    ];

    public function __construct(
        private BinanceService $binance,
        private BitgetService $bitget,
        private TechnicalAnalysisService $ta,
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

        $signals = [];
        $scanned = 0;

        foreach ($symbols as $symbol) {
            $klines = match($exchange) {
                'binance' => $this->binance->getKlines($symbol, $timeframe),
                'bitget'  => $this->bitget->getKlines($symbol, $timeframe),
                default   => [],
            };

            if (empty($klines)) {
                continue;
            }

            $analysis = $this->ta->analyze($klines);
            $scanned++;

            if ($analysis['signal_type'] === 'NEUTRAL') {
                continue;
            }

            $signal = $this->saveSignal($exchange, $symbol, $timeframe, $analysis);

            if ($signal) {
                $signals[] = $signal;
            }
        }

        $buy = count(array_filter($signals, fn($s) => $s->isBullish()));
        $sell = count(array_filter($signals, fn($s) => $s->isBearish()));

        Log::info("Scanned {$exchange} {$timeframe}: {$scanned} symbols → {$buy} BUY, {$sell} SELL signals");

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
            $windowMinutes = self::SCAN_WINDOW_MINUTES[$timeframe] ?? 60;

            $existing = MarketSignal::where('exchange', $exchange)
                ->where('symbol', $symbol)
                ->where('timeframe', $timeframe)
                ->where('created_at', '>=', now()->subMinutes($windowMinutes))
                ->latest()
                ->first();

            // Skip if signal type hasn't changed within the scan window
            if ($existing && $existing->signal_type === $data['signal_type']) {
                return null;
            }

            return MarketSignal::create([
                'exchange'           => $exchange,
                'symbol'             => $symbol,
                'timeframe'          => $timeframe,
                'signal_type'        => $data['signal_type'],
                'price'              => $data['price'],
                'rsi'                => $data['rsi'],
                'macd'               => $data['macd'],
                'macd_signal'        => $data['macd_signal'],
                'ema_20'             => $data['ema_20'],
                'ema_50'             => $data['ema_50'],
                'ema_200'            => $data['ema_200'],
                'volume'             => $data['volume'],
                'change_24h'         => null,
                'buy_indicators'     => $data['buy_indicators'],
                'sell_indicators'    => $data['sell_indicators'],
                'neutral_indicators' => $data['neutral_indicators'],
                'raw_data'           => $data,
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
            $watchedSymbols = $user->watchlists->pluck('symbol')
                ->map(fn($s) => strtoupper($s))->toArray();

            $watchedExchanges = $user->watchlists->pluck('exchange')->toArray();

            $userSignals = array_values(array_filter(
                $signals,
                function ($signal) use ($watchedSymbols, $watchedExchanges) {
                    return in_array(strtoupper($signal->symbol), $watchedSymbols)
                        && (in_array('both', $watchedExchanges) || in_array($signal->exchange, $watchedExchanges));
                }
            ));

            if (!empty($userSignals)) {
                try {
                    $user->notify(new MarketSignalNotification($userSignals, $timeframe));
                    foreach ($userSignals as $signal) {
                        $signal->update(['notification_sent' => true]);
                    }
                } catch (\Exception $e) {
                    Log::error("Failed to notify user {$user->id}: " . $e->getMessage());
                }
            }
        }
    }
}
