<?php

namespace App\Console\Commands;

use App\Services\SignalAnalyzerService;
use Illuminate\Console\Command;

class ScanMonthlyMarkets extends Command
{
    protected $signature = 'markets:monthly';
    protected $description = 'Scan for monthly timeframe trading signals on Binance and Bitget futures';

    public function handle(SignalAnalyzerService $analyzer): int
    {
        $this->info('Running monthly market scan...');
        $signals = $analyzer->scanAll('1M');
        $this->info('Monthly scan complete. Found ' . count($signals) . ' signals.');
        return self::SUCCESS;
    }
}
