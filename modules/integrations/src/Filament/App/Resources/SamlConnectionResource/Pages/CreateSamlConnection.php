<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Filament\App\Resources\SamlConnectionResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Liberu\Foundation\Integrations\Filament\App\Resources\SamlConnectionResource;

class CreateSamlConnection extends CreateRecord
{
    protected static string $resource = SamlConnectionResource::class;
}
