<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleLogResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleLogResource;

class ListTeamRoleLogs extends ListRecords
{
    protected static string $resource = TeamRoleLogResource::class;
}
