<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Filament\App\Resources\CallSettingResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Liberu\Foundation\Integrations\Filament\App\Resources\CallSettingResource;

class ListCallSettings extends ListRecords
{
    protected static string $resource = CallSettingResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
