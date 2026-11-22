<?php

declare(strict_types=1);

namespace Liberu\Integrations\Sso\Filament\App\Resources\SsoConnectionResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Liberu\Integrations\Sso\Filament\App\Resources\SsoConnectionResource;

class EditSsoConnection extends EditRecord
{
    protected static string $resource = SsoConnectionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
