<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketSignalResource\Pages;
use App\Models\MarketSignal;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class MarketSignalResource extends Resource
{
    protected static ?string $model = MarketSignal::class;
    protected static ?string $navigationIcon = 'heroicon-o-signal';
    protected static ?string $navigationLabel = 'Market Signals';
    protected static ?string $navigationGroup = 'Trading';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('exchange')
                    ->badge()
                    ->color(fn($state) => $state === 'binance' ? 'warning' : 'info')
                    ->formatStateUsing(fn($state) => strtoupper($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('symbol')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('timeframe')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn($state) => match($state) {
                        '1D' => 'Daily',
                        '1W' => 'Weekly',
                        '1M' => 'Monthly',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('signal_type')
                    ->badge()
                    ->sortable()
                    ->color(fn($state) => match($state) {
                        'STRONG_BUY' => 'success',
                        'BUY' => 'success',
                        'STRONG_SELL' => 'danger',
                        'SELL' => 'danger',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('price')
                    ->label('Price (USDT)')
                    ->formatStateUsing(fn($state) => $state ? '$' . number_format($state, 4) : '—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('rsi')
                    ->label('RSI')
                    ->formatStateUsing(fn($state) => $state ? number_format($state, 1) : '—')
                    ->color(fn($state) => match(true) {
                        $state > 70 => 'danger',
                        $state < 30 => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('change_24h')
                    ->label('24h %')
                    ->formatStateUsing(fn($state) => $state !== null ? number_format($state, 2) . '%' : '—')
                    ->color(fn($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'gray'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('buy_indicators')
                    ->label('Buy / Sell / Neutral')
                    ->formatStateUsing(fn($state, $record) => "{$record->buy_indicators} / {$record->sell_indicators} / {$record->neutral_indicators}"),

                Tables\Columns\IconColumn::make('notification_sent')
                    ->boolean()
                    ->label('Notified'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('exchange')
                    ->options(['binance' => 'Binance', 'bitget' => 'Bitget'])
                    ->multiple(),

                SelectFilter::make('timeframe')
                    ->options(['1D' => 'Daily', '1W' => 'Weekly', '1M' => 'Monthly'])
                    ->multiple(),

                SelectFilter::make('signal_type')
                    ->label('Signal')
                    ->options([
                        'STRONG_BUY' => 'Strong Buy',
                        'BUY' => 'Buy',
                        'SELL' => 'Sell',
                        'STRONG_SELL' => 'Strong Sell',
                        'NEUTRAL' => 'Neutral',
                    ])
                    ->multiple(),

                Filter::make('today')
                    ->label('Today only')
                    ->query(fn(Builder $q) => $q->where('created_at', '>=', now()->startOfDay())),

                Filter::make('actionable')
                    ->label('Actionable only')
                    ->query(fn(Builder $q) => $q->whereIn('signal_type', ['BUY', 'STRONG_BUY', 'SELL', 'STRONG_SELL']))
                    ->default(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('60s');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketSignals::route('/'),
        ];
    }
}
