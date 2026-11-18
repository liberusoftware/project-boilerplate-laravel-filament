<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Filament\Resources\TeamSubscriptionResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Liberu\Foundation\Organizations\Filament\Resources\TeamSubscriptionResource;

class CreateTeamSubscription extends CreateRecord
{
    protected static string $resource = TeamSubscriptionResource::class;
}
