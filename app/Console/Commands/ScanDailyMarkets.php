<?php

namespace App\Console\Commands;

use App\Services\SignalAnalyzerService;
use Illuminate\Console\Command;

class ScanDailyMarkets extends Command
{
    protected $signature = 'markets:daily';
    protected $description = 'Scan for daily timeframe trading signals on Binance and Bitget futures';

    public function handle(SignalAnalyzerService $analyzer): int
    {
        $this->info('Running daily market scan...');
        $signals = $analyzer->scanAll('1D');
        $this->info('Daily scan complete. Found ' . count($signals) . ' signals.');
        return self::SUCCESS;
    }
}
