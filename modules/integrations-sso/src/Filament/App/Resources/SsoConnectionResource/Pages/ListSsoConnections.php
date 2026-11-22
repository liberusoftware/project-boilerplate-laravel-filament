<?php

declare(strict_types=1);

namespace Liberu\Integrations\Sso\Filament\App\Resources\SsoConnectionResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Liberu\Integrations\Sso\Filament\App\Resources\SsoConnectionResource;

class ListSsoConnections extends ListRecords
{
    protected static string $resource = SsoConnectionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
