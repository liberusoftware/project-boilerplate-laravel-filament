<?php

namespace Liberu\Foundation\Organizations\Listeners;

use Illuminate\Auth\Events\Registered;
use Liberu\Foundation\Organizations\Services\TeamManagementService;

class CreatePersonalTeam
{
    public function __construct(protected TeamManagementService $teamManagementService) {}

    public function handle(Registered $event): void
    {
        $this->teamManagementService->assignUserToDefaultTeam($event->user);
    }
}
