<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WatchlistResource\Pages;
use App\Models\Watchlist;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WatchlistResource extends Resource
{
    protected static ?string $model = Watchlist::class;
    protected static ?string $navigationIcon = 'heroicon-o-bookmark';
    protected static ?string $navigationLabel = 'Watchlist';
    protected static ?string $navigationGroup = 'Trading';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Symbol Settings')->schema([
                Forms\Components\TextInput::make('symbol')
                    ->label('Symbol (e.g. BTCUSDT)')
                    ->required()
                    ->maxLength(20)
                    ->placeholder('BTCUSDT')
                    ->helperText('Enter the futures pair symbol without the exchange prefix'),

                Forms\Components\Select::make('exchange')
                    ->options([
                        'binance' => 'Binance',
                        'bitget' => 'Bitget',
                        'both' => 'Both Exchanges',
                    ])
                    ->default('both')
                    ->required(),

                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ])->columns(2),

            Forms\Components\Section::make('Notification Preferences')->schema([
                Forms\Components\Toggle::make('notify_daily')
                    ->label('Notify on Daily Signals')
                    ->default(true),

                Forms\Components\Toggle::make('notify_weekly')
                    ->label('Notify on Weekly Signals')
                    ->default(true),

                Forms\Components\Toggle::make('notify_monthly')
                    ->label('Notify on Monthly Signals')
                    ->default(true),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('symbol')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('exchange')
                    ->badge()
                    ->color(fn($state) => match($state) {
                        'binance' => 'warning',
                        'bitget' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn($state) => match($state) {
                        'binance' => 'Binance',
                        'bitget' => 'Bitget',
                        default => 'Both',
                    }),

                Tables\Columns\IconColumn::make('notify_daily')->boolean()->label('Daily'),
                Tables\Columns\IconColumn::make('notify_weekly')->boolean()->label('Weekly'),
                Tables\Columns\IconColumn::make('notify_monthly')->boolean()->label('Monthly'),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('Active'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWatchlists::route('/'),
            'create' => Pages\CreateWatchlist::route('/create'),
            'edit' => Pages\EditWatchlist::route('/{record}/edit'),
        ];
    }
}
