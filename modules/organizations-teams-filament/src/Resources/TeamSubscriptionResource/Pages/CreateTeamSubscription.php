<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource;

class CreateTeamSubscription extends CreateRecord
{
    protected static string $resource = TeamSubscriptionResource::class;
}
