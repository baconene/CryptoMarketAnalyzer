<?php

namespace App\Filament\Resources\MarketSignalResource\Pages;

use App\Filament\Resources\MarketSignalResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMarketSignal extends EditRecord
{
    protected static string $resource = MarketSignalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
