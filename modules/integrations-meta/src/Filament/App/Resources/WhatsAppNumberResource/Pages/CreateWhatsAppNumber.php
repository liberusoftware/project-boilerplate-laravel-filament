<?php

declare(strict_types=1);

namespace Liberu\Integrations\Meta\Filament\App\Resources\WhatsAppNumberResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Liberu\Integrations\Meta\Filament\App\Resources\WhatsAppNumberResource;

class CreateWhatsAppNumber extends CreateRecord
{
    protected static string $resource = WhatsAppNumberResource::class;
}
