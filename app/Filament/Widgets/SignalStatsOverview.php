<?php

namespace App\Filament\Widgets;

use App\Models\MarketSignal;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SignalStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $todayBuy = MarketSignal::where('created_at', '>=', now()->startOfDay())
            ->whereIn('signal_type', ['BUY', 'STRONG_BUY'])
            ->count();

        $todaySell = MarketSignal::where('created_at', '>=', now()->startOfDay())
            ->whereIn('signal_type', ['SELL', 'STRONG_SELL'])
            ->count();

        $weeklyBuy = MarketSignal::where('timeframe', '1W')
            ->whereIn('signal_type', ['BUY', 'STRONG_BUY'])
            ->where('created_at', '>=', now()->subWeek())
            ->count();

        $monthlyBuy = MarketSignal::where('timeframe', '1M')
            ->whereIn('signal_type', ['BUY', 'STRONG_BUY'])
            ->where('created_at', '>=', now()->subMonth())
            ->count();

        $strongSignals = MarketSignal::whereIn('signal_type', ['STRONG_BUY', 'STRONG_SELL'])
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        return [
            Stat::make('Daily BUY Signals', $todayBuy)
                ->description('Today\'s bullish opportunities')
                ->color('success')
                ->icon('heroicon-o-arrow-trending-up'),

            Stat::make('Daily SELL Signals', $todaySell)
                ->description('Today\'s bearish opportunities')
                ->color('danger')
                ->icon('heroicon-o-arrow-trending-down'),

            Stat::make('Weekly BUY Signals', $weeklyBuy)
                ->description('This week\'s bullish signals')
                ->color('info')
                ->icon('heroicon-o-calendar'),

            Stat::make('Monthly BUY Signals', $monthlyBuy)
                ->description('This month\'s bullish signals')
                ->color('warning')
                ->icon('heroicon-o-chart-bar'),

            Stat::make('Strong Signals Today', $strongSignals)
                ->description('STRONG BUY + STRONG SELL')
                ->color('primary')
                ->icon('heroicon-o-bolt'),
        ];
    }
}
