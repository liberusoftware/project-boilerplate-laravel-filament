<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Filament\App\Resources\TeamRoleLogResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Foundation\Organizations\Filament\App\Resources\TeamRoleLogResource;

class ListTeamRoleLogs extends ListRecords
{
    protected static string $resource = TeamRoleLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
