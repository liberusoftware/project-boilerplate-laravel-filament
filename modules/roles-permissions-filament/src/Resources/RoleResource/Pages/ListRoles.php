<?php

declare(strict_types=1);

namespace Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function getSubheading(): string
    {
        return 'Global definitions apply across teams. Team-specific roles are managed separately under Custom roles.';
    }
}
