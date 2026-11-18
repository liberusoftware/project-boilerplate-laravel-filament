<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Filament\App\Resources\TerritoryResource\Pages;

use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Liberu\Foundation\Organizations\Filament\App\Resources\TerritoryResource;

class ViewTerritory extends ViewRecord
{
    protected static string $resource = TerritoryResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name'),
            TextEntry::make('created_at')->dateTime(),
            TextEntry::make('users.name')
                ->label('Members')
                ->listWithLineBreaks()
                ->badge(),
        ]);
    }
}
