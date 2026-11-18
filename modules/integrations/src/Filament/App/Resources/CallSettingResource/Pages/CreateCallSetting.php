<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Filament\App\Resources\CallSettingResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Liberu\Foundation\Integrations\Filament\App\Resources\CallSettingResource;

class CreateCallSetting extends CreateRecord
{
    protected static string $resource = CallSettingResource::class;
}
