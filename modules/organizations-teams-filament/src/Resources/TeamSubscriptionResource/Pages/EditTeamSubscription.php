<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource;

class EditTeamSubscription extends EditRecord
{
    protected static string $resource = TeamSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
