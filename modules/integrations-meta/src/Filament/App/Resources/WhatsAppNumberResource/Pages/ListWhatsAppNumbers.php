<?php

declare(strict_types=1);

namespace Liberu\Integrations\Meta\Filament\App\Resources\WhatsAppNumberResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Liberu\Integrations\Meta\Filament\App\Resources\WhatsAppNumberResource;

class ListWhatsAppNumbers extends ListRecords
{
    protected static string $resource = WhatsAppNumberResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
