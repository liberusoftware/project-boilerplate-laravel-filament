<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Filament\App\Resources\TerritoryResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Liberu\Foundation\Organizations\Filament\App\Resources\TerritoryResource;

class EditTerritory extends EditRecord
{
    protected static string $resource = TerritoryResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
