<?php

namespace App\Filament\Resources\MarketSignalResource\Pages;

use App\Filament\Resources\MarketSignalResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMarketSignals extends ListRecords
{
    protected static string $resource = MarketSignalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('scan_now')
                ->label('Scan Now (Daily)')
                ->icon('heroicon-o-magnifying-glass')
                ->color('success')
                ->action(function () {
                    \Artisan::call('markets:daily');
                    \Filament\Notifications\Notification::make()
                        ->title('Daily scan triggered')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\SignalStatsOverview::class,
        ];
    }
}
