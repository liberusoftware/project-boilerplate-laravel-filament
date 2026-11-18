<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Filament\Resources\TeamSubscriptionResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Liberu\Foundation\Organizations\Filament\Resources\TeamSubscriptionResource;

class EditTeamSubscription extends EditRecord
{
    protected static string $resource = TeamSubscriptionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
