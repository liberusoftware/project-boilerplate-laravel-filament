<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Filament\App\Resources\SsoConnectionResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Liberu\Foundation\Integrations\Filament\App\Resources\SsoConnectionResource;

class CreateSsoConnection extends CreateRecord
{
    protected static string $resource = SsoConnectionResource::class;
}
