<?php

namespace Liberu\Foundation\Organizations\Services;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Jetstream\Jetstream;
use Liberu\Foundation\Organizations\Contracts\TeamAuditLogger;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\RolesPermissions\Enums\Role;
use Liberu\Foundation\RolesPermissions\Services\RoleConfigurationService;
use Liberu\Foundation\RolesPermissions\Support\RolePermissionCatalog;
use Spatie\Permission\Models\Role as SpatieRole;

class TeamManagementService
{
    /** Roles a team admin may assign to a member (super_admin is global, customer is portal). */
    public const TEAM_ROLES = [Role::Manager, Role::SalesRep, Role::Free];

    public function __construct(
        private readonly ?TeamAuditLogger $auditLogger = null,
    ) {}

    public function defaultMemberRole(): Role
    {
        $configured = (string) config('organizations-teams.default_member_role', Role::SalesRep->value);

        return Role::tryFrom($configured) ?? Role::SalesRep;
    }

    public function createTeamMember(Team $team, string $name, string $email, string $password, Role $role): Model
    {
        $this->authorizeMemberManagement($team);
        setPermissionsTeamId($team->getKey());

        if (! in_array($role, self::TEAM_ROLES, true)) {
            throw new InvalidArgumentException('That role cannot be assigned to a new team member.');
        }

        $userModel = Jetstream::userModel();

        if ($userModel::where('email', $email)->exists()) {
            throw new InvalidArgumentException('That email address is already registered.');
        }

        if (mb_strlen($password) < 12) {
            throw new InvalidArgumentException('Passwords must contain at least 12 characters.');
        }

        return DB::transaction(function () use ($team, $name, $email, $password, $role, $userModel): Model {
            $user = $userModel::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $team->users()->attach($user, ['role' => 'member']);
            $this->assignTeamRole($user, $team, $role);

            $this->audit()->record(
                'team.member_added',
                "Added {$email} to the team with role {$role->value}",
                $user,
            );

            return $user;
        });
    }

    public function addTeamMember(Model $user, Team $team, Role $role): void
    {
        $this->authorizeMemberManagement($team);
        if (! in_array($role, self::TEAM_ROLES, true)) {
            throw new InvalidArgumentException('That role cannot be assigned to a team member.');
        }
        DB::transaction(function () use ($user, $team, $role): void {
            if (! $user->belongsToTeam($team)) {
                $team->users()->attach($user);
                $user->unsetRelation('teams');
            }
            $this->changeTeamRole($user, $team, $role);
        });
    }

    public function removeTeamMember(Model $user, Team $team): void
    {
        $this->authorizeMemberManagement($team, $user);
        if ($user->getKey() === $team->getAttribute('user_id')) {
            throw new InvalidArgumentException('The team owner cannot be removed.');
        }

        setPermissionsTeamId($team->getKey());

        DB::transaction(function () use ($user, $team): void {
            $this->clearTeamRoles($user, $team);

            $team->users()->detach($user);

            $this->audit()->record(
                'team.member_removed',
                "Removed {$user->getAttribute('email')} from the team",
                $user,
            );
        });
    }

    public function changeTeamRole(Model $user, Team $team, Role $role): void
    {
        $this->authorizeMemberManagement($team, $user);
        $this->provisionTeamRole($user, $team, $role);
    }

    /** Called only after invitation or identity-provider verification, before an actor session may exist. */
    public function provisionTeamRole(Model $user, Team $team, Role $role): void
    {
        if (! $team->users()->whereKey($user->getKey())->exists()) {
            throw new InvalidArgumentException('The user is not a member of this team.');
        }
        if (! in_array($role, self::TEAM_ROLES, true)) {
            throw new InvalidArgumentException("Role {$role->value} is not assignable to a team member.");
        }

        if ($user->getKey() === $team->getAttribute('user_id')) {
            throw new InvalidArgumentException("The team owner's role cannot be changed.");
        }

        setPermissionsTeamId($team->getKey());
        $definition = SpatieRole::firstOrCreate(['name' => $role->value, 'guard_name' => 'web', 'team_id' => null]);

        $previous = $user->getRoleNames()->first() ?? 'none';

        DB::transaction(function () use ($user, $team, $definition, $previous, $role): void {
            $this->clearTeamRoles($user, $team);
            $user->assignRole($definition);

            $this->audit()->record(
                'team.role_changed',
                "Changed {$user->getAttribute('email')} from {$previous} to {$role->value}",
                $user,
            );
        });
    }

    public function assignCustomRole(Model $user, Team $team, SpatieRole $customRole): void
    {
        $this->authorizeMemberManagement($team, $user);

        if ((int) $customRole->getAttribute('team_id') !== (int) $team->getKey()) {
            throw new InvalidArgumentException('The role does not belong to this team.');
        }

        if ($user->getKey() === $team->getAttribute('user_id')) {
            throw new InvalidArgumentException("The team owner's role cannot be changed.");
        }

        setPermissionsTeamId($team->getKey());

        $previous = $user->getRoleNames()->first() ?? 'none';

        if ($customRole->guard_name !== 'web' || in_array(mb_strtolower($customRole->name), Role::values(), true)
            || array_diff($customRole->getPermissionNames()->all(), array_keys(RolePermissionCatalog::options())) !== []) {
            throw new InvalidArgumentException('This custom role contains permissions that cannot be assigned by a team owner.');
        }

        $newRole = $customRole->getAttribute('name');
        DB::transaction(function () use ($user, $team, $customRole, $previous, $newRole): void {
            $customRole = SpatieRole::query()->whereKey($customRole->getKey())->lockForUpdate()->firstOrFail();
            $this->clearTeamRoles($user, $team);
            $user->assignRole($customRole);

            $this->audit()->record(
                'team.role_changed',
                "Changed {$user->getAttribute('email')} from {$previous} to {$newRole}",
                $user,
            );
        });
    }

    private function authorizeMemberManagement(Team $team, ?Model $member = null): void
    {
        $actor = Auth::user();
        if (! $actor instanceof Model || ! app(RoleConfigurationService::class)->canManageTeam($actor, $team)) {
            throw new InvalidArgumentException('You cannot manage members for this team.');
        }
        if ($member !== null && (! $member->belongsToTeam($team) || $member->getKey() === $actor->getKey())) {
            throw new InvalidArgumentException('Choose another member of this team.');
        }
    }

    private function clearTeamRoles(Model $user, Team $team): void
    {
        DB::table('model_has_roles')
            ->where('team_id', $team->getKey())
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->delete();
        $user->unsetRelation('roles')->unsetRelation('permissions');
    }

    public function createPersonalTeamForUser(Model $user): Team
    {
        $team = $user->ownedTeams()->create([
            'name' => $user->getAttribute('name')."'s Team",
            'personal_team' => true,
        ]);

        $this->assignTeamRole($user, $team, Role::Admin);

        return $team;
    }

    public function assignTeamRole(Model $user, Team $team, Role $role): void
    {
        setPermissionsTeamId($team->getKey());
        SpatieRole::firstOrCreate([
            'name' => $role->value,
            'guard_name' => 'web',
            'team_id' => null,
        ]);
        $user->assignRole($role->value);
    }

    public function assignUserToDefaultTeam(Model $user): void
    {
        $defaultTeam = Team::where('personal_team', false)->first();

        if (! $defaultTeam) {
            $defaultTeam = $this->createPersonalTeamForUser($user);
            $user->setAttribute('current_team_id', $defaultTeam->id);
            $user->save();

            return;
        }

        $this->assignUserToTeam($user, $defaultTeam);
    }

    public function assignUserToTeam(Model $user, Team $team): void
    {
        if (! $user->belongsToTeam($team)) {
            $user->teams()->attach($team, ['role' => 'member']);
            $user->unsetRelation('teams');
            $this->assignTeamRole($user, $team, $this->defaultMemberRole());
        }
        $user->switchTeam($team);
    }

    public function switchTeam(Model $user, Team $team): void
    {
        if (! $user->belongsToTeam($team)) {
            throw new Exception('User does not belong to the specified team.');
        }
        $user->switchTeam($team);
    }

    private function audit(): TeamAuditLogger
    {
        return $this->auditLogger ?? app(TeamAuditLogger::class);
    }
}
