<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource;

class ListTeamRoles extends ListRecords
{
    protected static string $resource = TeamRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
