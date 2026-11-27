<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Listeners;

use Illuminate\Database\Eloquent\Model;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\TeamManagementService;

class AssignDefaultTeamRole
{
    public function __construct(private readonly TeamManagementService $teams) {}

    public function handle(TeamMemberAdded $event): void
    {
        if ($event->team instanceof Team && $event->user instanceof Model) {
            $this->teams->assignTeamRole($event->user, $event->team, $this->teams->defaultMemberRole());
        }
    }
}
