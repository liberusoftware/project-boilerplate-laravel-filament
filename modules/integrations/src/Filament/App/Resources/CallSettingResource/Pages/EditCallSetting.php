<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Filament\App\Resources\CallSettingResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Liberu\Foundation\Integrations\Filament\App\Resources\CallSettingResource;

class EditCallSetting extends EditRecord
{
    protected static string $resource = CallSettingResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
