<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Filament\App\Resources\WhatsAppNumberResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Liberu\Foundation\Integrations\Filament\App\Resources\WhatsAppNumberResource;

class EditWhatsAppNumber extends EditRecord
{
    protected static string $resource = WhatsAppNumberResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
