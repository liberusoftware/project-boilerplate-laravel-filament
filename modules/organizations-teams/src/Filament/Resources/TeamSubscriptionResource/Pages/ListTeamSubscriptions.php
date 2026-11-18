<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Filament\Resources\TeamSubscriptionResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Liberu\Foundation\Organizations\Filament\Resources\TeamSubscriptionResource;

class ListTeamSubscriptions extends ListRecords
{
    protected static string $resource = TeamSubscriptionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
