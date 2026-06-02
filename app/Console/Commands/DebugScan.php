<?php

namespace App\Console\Commands;

use App\Services\BinanceService;
use App\Services\BitgetService;
use App\Services\TechnicalAnalysisService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DebugScan extends Command
{
    protected $signature = 'markets:debug
                            {--exchange=binance : Exchange to debug (binance or bitget)}
                            {--symbol=BTCUSDT : Symbol to test}
                            {--timeframe=1D : Timeframe (1D, 1W, 1M)}
                            {--flush : Clear all market caches before testing}';

    protected $description = 'Debug the market scan pipeline step by step';

    public function handle(BinanceService $binance, BitgetService $bitget, TechnicalAnalysisService $ta): int
    {
        if ($this->option('flush')) {
            Cache::flush();
            $this->info('Cache cleared.');
        }

        $exchange  = $this->option('exchange');
        $symbol    = strtoupper($this->option('symbol'));
        $timeframe = strtoupper($this->option('timeframe'));

        $this->newLine();
        $this->line("=== CryptoMarketAnalyzer Debug ===");
        $this->line("Exchange: {$exchange} | Symbol: {$symbol} | Timeframe: {$timeframe}");
        $this->newLine();

        // Step 1: connectivity
        $this->line('[1] Testing API connectivity...');
        $service = $exchange === 'bitget' ? $bitget : $binance;

        $tickers = $exchange === 'bitget'
            ? $bitget->getAllTickers()
            : $binance->getAllTickers();

        if (empty($tickers)) {
            $this->error("  ✗ Could not fetch tickers from {$exchange}. Check network or API status.");
            $this->line('    Check storage/logs/laravel.log for the HTTP error.');
            return self::FAILURE;
        }

        $this->info("  ✓ Tickers fetched: " . count($tickers) . " symbols");

        // Step 2: top symbols
        $this->line('[2] Top symbols by volume:');
        $topSymbols = $exchange === 'bitget'
            ? $bitget->getTopSymbolsByVolume(5)
            : $binance->getTopSymbolsByVolume(5);

        $this->table(['#', 'Symbol'], array_map(fn($s, $i) => [$i + 1, $s], $topSymbols, array_keys($topSymbols)));

        // Step 3: klines
        $this->line("[3] Fetching klines for {$symbol} ({$timeframe})...");
        $klines = $exchange === 'bitget'
            ? $bitget->getKlines($symbol, $timeframe)
            : $binance->getKlines($symbol, $timeframe);

        if (empty($klines)) {
            $this->error("  ✗ No klines returned for {$symbol}. Check logs for the HTTP error.");
            return self::FAILURE;
        }

        $last = end($klines);
        $this->info("  ✓ Klines received: " . count($klines) . " candles");
        $this->line("    Latest close: " . number_format($last['close'], 4));
        $this->line("    Latest volume: " . number_format($last['volume'], 2));

        if (count($klines) < 30) {
            $this->warn("  ⚠ Only " . count($klines) . " candles — need ≥30 for analysis.");
        }

        // Step 4: technical analysis
        $this->line('[4] Running technical analysis...');
        $result = $ta->analyze($klines);

        $this->info("  Signal:   " . $result['signal_type']);
        $this->line("  RSI:      " . ($result['rsi'] ?? 'n/a (need more candles)'));
        $this->line("  MACD:     " . ($result['macd'] !== null ? number_format($result['macd'], 6) : 'n/a'));
        $this->line("  EMA 20:   " . ($result['ema_20'] !== null ? number_format($result['ema_20'], 4) : 'n/a (need ≥20 candles)'));
        $this->line("  EMA 50:   " . ($result['ema_50'] !== null ? number_format($result['ema_50'], 4) : 'n/a (need ≥50 candles)'));
        $this->line("  EMA 200:  " . ($result['ema_200'] !== null ? number_format($result['ema_200'], 4) : 'n/a (need ≥200 candles)'));

        $this->newLine();
        $this->line(sprintf(
            "  Indicators: %d BUY  |  %d SELL  |  %d NEUTRAL",
            $result['buy_indicators'],
            $result['sell_indicators'],
            $result['neutral_indicators']
        ));

        if ($result['signal_type'] === 'NEUTRAL') {
            $total = $result['buy_indicators'] + $result['sell_indicators'] + $result['neutral_indicators'];
            $buyPct = $total > 0 ? round($result['buy_indicators'] / $total * 100) : 0;
            $sellPct = $total > 0 ? round($result['sell_indicators'] / $total * 100) : 0;
            $this->warn("  Signal is NEUTRAL ({$buyPct}% buy / {$sellPct}% sell — need ≥55% for BUY/SELL)");
        }

        $this->newLine();
        $this->info('Debug complete. If all steps passed, the full scan should produce results.');
        $this->line('Run: php artisan markets:scan 1D --top=50');

        return self::SUCCESS;
    }
}
