<?php

namespace App\Console\Commands;

use App\Services\SignalAnalyzerService;
use Illuminate\Console\Command;

class ScanWeeklyMarkets extends Command
{
    protected $signature = 'markets:weekly';
    protected $description = 'Scan for weekly timeframe trading signals on Binance and Bitget futures';

    public function handle(SignalAnalyzerService $analyzer): int
    {
        $this->info('Running weekly market scan...');
        $signals = $analyzer->scanAll('1W');
        $this->info('Weekly scan complete. Found ' . count($signals) . ' signals.');
        return self::SUCCESS;
    }
}
