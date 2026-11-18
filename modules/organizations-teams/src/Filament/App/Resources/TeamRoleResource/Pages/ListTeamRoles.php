<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Filament\App\Resources\TeamRoleResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Liberu\Foundation\Organizations\Filament\App\Resources\TeamRoleResource;

class ListTeamRoles extends ListRecords
{
    protected static string $resource = TeamRoleResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
