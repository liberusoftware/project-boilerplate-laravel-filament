<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Listeners;

use App\Models\User;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Services\TeamManagementService;
use Liberu\Foundation\RolesPermissions\Enums\Role;

/**
 * A user added to a team (Jetstream addTeamMember, which also runs on invitation
 * acceptance) gets the default sales_rep role scoped to that team.
 */
class AssignDefaultTeamRole
{
    public function __construct(private readonly TeamManagementService $teams) {}

    public function handle(TeamMemberAdded $event): void
    {
        if ($event->team instanceof Team && $event->user instanceof User) {
            $this->teams->assignTeamRole($event->user, $event->team, Role::SalesRep);
        }
    }
}
