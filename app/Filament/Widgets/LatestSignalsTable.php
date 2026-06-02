<?php

namespace App\Filament\Widgets;

use App\Models\MarketSignal;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestSignalsTable extends BaseWidget
{
    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                MarketSignal::query()
                    ->whereIn('signal_type', ['BUY', 'STRONG_BUY', 'SELL', 'STRONG_SELL'])
                    ->latest()
                    ->limit(20)
            )
            ->heading('Latest Trading Signals')
            ->columns([
                Tables\Columns\TextColumn::make('exchange')
                    ->badge()
                    ->color(fn($record) => $record->exchange === 'binance' ? 'warning' : 'info')
                    ->formatStateUsing(fn($state) => strtoupper($state)),

                Tables\Columns\TextColumn::make('symbol')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('timeframe')
                    ->badge()
                    ->formatStateUsing(fn($state) => match($state) {
                        '1D' => 'Daily',
                        '1W' => 'Weekly',
                        '1M' => 'Monthly',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('signal_type')
                    ->badge()
                    ->color(fn($state) => match($state) {
                        'STRONG_BUY' => 'success',
                        'BUY' => 'success',
                        'STRONG_SELL' => 'danger',
                        'SELL' => 'danger',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('price')
                    ->money('USD')
                    ->sortable(),

                Tables\Columns\TextColumn::make('rsi')
                    ->label('RSI')
                    ->formatStateUsing(fn($state) => $state ? number_format($state, 1) : '—')
                    ->color(fn($state) => match(true) {
                        $state > 70 => 'danger',
                        $state < 30 => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('change_24h')
                    ->label('24h Change')
                    ->formatStateUsing(fn($state) => $state ? number_format($state, 2) . '%' : '—')
                    ->color(fn($state) => $state > 0 ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('buy_indicators')
                    ->label('Buy/Sell')
                    ->formatStateUsing(fn($state, $record) => "{$record->buy_indicators} / {$record->sell_indicators}"),

                Tables\Columns\IconColumn::make('notification_sent')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated(false);
    }
}
