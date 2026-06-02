<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Database\Seeder;

class WatchlistSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first();

        if (!$user) {
            return;
        }

        $defaultSymbols = [
            'BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'SOLUSDT', 'XRPUSDT',
            'ADAUSDT', 'AVAXUSDT', 'DOGEUSDT', 'DOTUSDT', 'LINKUSDT',
        ];

        foreach ($defaultSymbols as $symbol) {
            Watchlist::firstOrCreate(
                ['user_id' => $user->id, 'symbol' => $symbol, 'exchange' => 'both'],
                [
                    'notify_daily' => true,
                    'notify_weekly' => true,
                    'notify_monthly' => true,
                    'is_active' => true,
                ]
            );
        }
    }
}
