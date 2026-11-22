<?php

declare(strict_types=1);

namespace Liberu\Integrations\Sso\Filament\App\Resources\SamlConnectionResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Liberu\Integrations\Sso\Filament\App\Resources\SamlConnectionResource;

class EditSamlConnection extends EditRecord
{
    protected static string $resource = SamlConnectionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
