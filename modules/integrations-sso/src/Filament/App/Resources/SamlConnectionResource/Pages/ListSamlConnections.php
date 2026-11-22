<?php

declare(strict_types=1);

namespace Liberu\Integrations\Sso\Filament\App\Resources\SamlConnectionResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Liberu\Integrations\Sso\Filament\App\Resources\SamlConnectionResource;

class ListSamlConnections extends ListRecords
{
    protected static string $resource = SamlConnectionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
