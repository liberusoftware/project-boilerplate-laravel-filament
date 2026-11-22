<?php

declare(strict_types=1);

namespace Liberu\Integrations\Sso\Filament\App\Resources\SsoConnectionResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Liberu\Integrations\Sso\Filament\App\Resources\SsoConnectionResource;

class CreateSsoConnection extends CreateRecord
{
    protected static string $resource = SsoConnectionResource::class;
}
