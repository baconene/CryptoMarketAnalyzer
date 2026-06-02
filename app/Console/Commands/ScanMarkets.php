<?php

namespace App\Console\Commands;

use App\Services\SignalAnalyzerService;
use Illuminate\Console\Command;

class ScanMarkets extends Command
{
    protected $signature = 'markets:scan
                            {timeframe=1D : Timeframe to scan (1D, 1W, 1M)}
                            {--exchange= : Specific exchange (binance, bitget). Scans both if omitted.}
                            {--top=50 : Number of top symbols to scan by volume}';

    protected $description = 'Scan Binance and Bitget futures markets for trading signals';

    public function handle(SignalAnalyzerService $analyzer): int
    {
        $timeframe = strtoupper($this->argument('timeframe'));
        $exchange = $this->option('exchange');
        $top = (int) $this->option('top');

        $validTimeframes = ['1D', '1W', '1M'];
        if (!in_array($timeframe, $validTimeframes)) {
            $this->error("Invalid timeframe '{$timeframe}'. Use: " . implode(', ', $validTimeframes));
            return self::FAILURE;
        }

        $timeframeLabel = match($timeframe) {
            '1D' => 'Daily',
            '1W' => 'Weekly',
            '1M' => 'Monthly',
        };

        $this->info("Starting {$timeframeLabel} market scan (top {$top} symbols by volume)...");

        $exchanges = $exchange ? [$exchange] : ['binance', 'bitget'];
        $totalSignals = [];

        foreach ($exchanges as $ex) {
            $this->line("  Scanning {$ex}...");
            $signals = $analyzer->scanExchange($ex, $timeframe, $top);
            $totalSignals = array_merge($totalSignals, $signals);

            $buy = count(array_filter($signals, fn($s) => $s->isBullish()));
            $sell = count(array_filter($signals, fn($s) => $s->isBearish()));

            $this->line("  → {$ex}: {$buy} BUY, {$sell} SELL signals");
        }

        if (count($exchanges) > 1) {
            $analyzer->scanAll($timeframe); // triggers notifications
        }

        $buy = count(array_filter($totalSignals, fn($s) => $s->isBullish()));
        $sell = count(array_filter($totalSignals, fn($s) => $s->isBearish()));

        $this->info("Scan complete: {$buy} BUY + {$sell} SELL = " . count($totalSignals) . " total signals");

        if (!empty($totalSignals)) {
            $this->newLine();
            $this->table(
                ['Exchange', 'Symbol', 'Signal', 'Price', 'RSI', 'Buy Ind.', 'Sell Ind.'],
                collect($totalSignals)->take(20)->map(fn($s) => [
                    strtoupper($s->exchange),
                    $s->symbol,
                    $s->signal_type,
                    number_format($s->price ?? 0, 4),
                    number_format($s->rsi ?? 0, 1),
                    $s->buy_indicators,
                    $s->sell_indicators,
                ])->toArray()
            );
        }

        return self::SUCCESS;
    }
}
