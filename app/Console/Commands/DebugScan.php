<?php

namespace App\Console\Commands;

use App\Services\BinanceService;
use App\Services\BitgetService;
use App\Services\TechnicalAnalysisService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
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

        $exchange  = strtolower($this->option('exchange'));
        $symbol    = strtoupper($this->option('symbol'));
        $timeframe = strtoupper($this->option('timeframe'));

        $this->newLine();
        $this->line('=== CryptoMarketAnalyzer Debug ===');
        $this->line("Exchange: {$exchange} | Symbol: {$symbol} | Timeframe: {$timeframe}");
        $this->newLine();

        // ── Step 1: raw connectivity ──────────────────────────────────────
        $this->line('[1] Testing raw connectivity...');

        if ($exchange === 'binance') {
            $results = $binance->testConnectivity();
            foreach ($results as $url => $status) {
                $ok = $status === 'OK';
                $icon = $ok ? '  ✓' : '  ✗';
                $method = $ok ? 'info' : 'warn';
                $this->$method("{$icon} {$url}  →  {$status}");
            }
            $anyOk = in_array('OK', $results);
        } else {
            [$ok, $msg] = $this->testBitgetPing();
            $icon = $ok ? '  ✓' : '  ✗';
            $method = $ok ? 'info' : 'warn';
            $this->$method("{$icon} https://api.bitget.com  →  {$msg}");
            $anyOk = $ok;
        }

        if (!$anyOk) {
            $this->newLine();
            $this->error('Cannot reach the exchange API from this server.');
            $this->line('Possible causes:');
            $this->line('  • Server IP is geo-blocked by the exchange (common for US-based cloud servers)');
            $this->line('  • Firewall / outbound port 443 blocked');
            $this->line('  • Exchange is down');
            $this->line('');
            $this->line('Solutions:');
            $this->line('  • Change your Forge server region to Singapore / EU');
            $this->line('  • Or add a proxy in .env: HTTPS_PROXY=http://your-proxy:port');
            return self::FAILURE;
        }

        // ── Step 2: fetch tickers ────────────────────────────────────────
        $this->newLine();
        $this->line('[2] Fetching tickers...');
        $tickers = $exchange === 'bitget' ? $bitget->getAllTickers() : $binance->getAllTickers();

        if (empty($tickers)) {
            $this->error('  ✗ Ticker list is empty. Check storage/logs/laravel.log.');
            return self::FAILURE;
        }

        $this->info('  ✓ Tickers: ' . count($tickers) . ' symbols');
        $topSymbols = $exchange === 'bitget'
            ? $bitget->getTopSymbolsByVolume(5)
            : $binance->getTopSymbolsByVolume(5);
        $this->line('  Top 5: ' . implode(', ', $topSymbols));

        // ── Step 3: klines ───────────────────────────────────────────────
        $this->newLine();
        $this->line("[3] Fetching klines for {$symbol} ({$timeframe})...");
        $klines = $exchange === 'bitget'
            ? $bitget->getKlines($symbol, $timeframe)
            : $binance->getKlines($symbol, $timeframe);

        if (empty($klines)) {
            $this->error("  ✗ No klines for {$symbol}. Check storage/logs/laravel.log.");
            return self::FAILURE;
        }

        $last = end($klines);
        $this->info('  ✓ Candles: ' . count($klines));
        $this->line('    Last close : $' . number_format($last['close'], 4));
        $this->line('    Last volume: ' . number_format($last['volume'], 2));

        // ── Step 4: TA analysis ──────────────────────────────────────────
        $this->newLine();
        $this->line('[4] Technical analysis...');
        $result = $ta->analyze($klines);

        $signalColor = match($result['signal_type']) {
            'STRONG_BUY', 'BUY' => 'info',
            'STRONG_SELL', 'SELL' => 'warn',
            default => 'line',
        };
        $this->$signalColor('  Signal : ' . $result['signal_type']);
        $this->line('  RSI    : ' . ($result['rsi'] !== null ? number_format($result['rsi'], 2) : 'n/a'));
        $this->line('  MACD   : ' . ($result['macd'] !== null ? number_format($result['macd'], 6) : 'n/a'));
        $this->line('  EMA20  : ' . ($result['ema_20'] !== null ? number_format($result['ema_20'], 4) : 'n/a'));
        $this->line('  EMA50  : ' . ($result['ema_50'] !== null ? number_format($result['ema_50'], 4) : 'n/a'));
        $this->line('  EMA200 : ' . ($result['ema_200'] !== null ? number_format($result['ema_200'], 4) : 'n/a (need ≥200 candles)'));
        $this->line(sprintf(
            '  Votes  : %d BUY / %d SELL / %d NEUTRAL',
            $result['buy_indicators'],
            $result['sell_indicators'],
            $result['neutral_indicators']
        ));

        $this->newLine();
        if ($result['signal_type'] === 'NEUTRAL') {
            $total = $result['buy_indicators'] + $result['sell_indicators'] + $result['neutral_indicators'];
            $pct = $total > 0 ? round(max($result['buy_indicators'], $result['sell_indicators']) / $total * 100) : 0;
            $this->warn("  NEUTRAL — strongest side is {$pct}% (need ≥55% to trigger BUY/SELL)");
        } else {
            $this->info('  ✓ Signal would be saved to the database during a real scan.');
        }

        $this->newLine();
        $this->info('All steps passed. Run the full scan:');
        $this->line('  php artisan markets:scan 1D');

        return self::SUCCESS;
    }

    private function testBitgetPing(): array
    {
        try {
            $client = new Client(['timeout' => 8]);
            $r = $client->get('https://api.bitget.com/api/mix/v1/market/tickers?productType=umcbl');
            $data = json_decode($r->getBody(), true);
            $count = count($data['data'] ?? []);
            return [true, "OK ({$count} tickers)"];
        } catch (RequestException $e) {
            $response = $e->hasResponse() ? $e->getResponse() : null;
            $msg = $response
                ? 'HTTP ' . $response->getStatusCode() . ' — ' . substr((string) $response->getBody(), 0, 120)
                : $e->getMessage();
            return [false, $msg];
        } catch (\Exception $e) {
            return [false, $e->getMessage()];
        }
    }
}
