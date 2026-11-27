<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\RolesPermissions\Enums\Role as RoleEnum;
use Liberu\Foundation\RolesPermissions\Services\RoleConfigurationService;
use Liberu\Foundation\RolesPermissions\Support\PermissionCatalog;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config(['permissions' => [
        'actions' => ['view', 'create', 'update', 'delete'],
        'groups' => [
            'core' => ['contact', 'company', 'lead', 'deal', 'opportunity', 'task', 'note', 'message'],
            'advertising' => ['advertising_account', 'campaign'],
            'settings' => ['team_member', 'team_role', 'territory'],
            'logs' => ['audit_log'],
            'staff' => ['knowledge_base_article'],
        ],
        'matrix' => [
            'admin' => [
                'groups' => ['core' => ['view', 'create', 'update', 'delete']],
            ],
        ],
    ]]);

    PermissionCatalog::sync();
});

it('evaluates canManageTeam for super admins, owners, outsiders, plain members, and team admins', function (): void {
    $service = new RoleConfigurationService();

    $superAdmin = User::factory()->create();
    setPermissionsTeamId(null);
    $superAdmin->assignRole(RoleEnum::SuperAdmin->value);

    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);

    $outsider = User::factory()->create();

    $member = User::factory()->create();
    $team->users()->attach($member, ['role' => 'editor']);

    $teamAdmin = User::factory()->create();
    $team->users()->attach($teamAdmin, ['role' => 'admin']);

    $globalAdminRole = SpatieRole::query()
        ->whereNull('team_id')
        ->where('guard_name', 'web')
        ->where('name', RoleEnum::Admin->value)
        ->firstOrFail();

    DB::table('model_has_roles')->insert([
        'role_id' => $globalAdminRole->id,
        'model_type' => $teamAdmin->getMorphClass(),
        'model_id' => $teamAdmin->getKey(),
        'team_id' => $team->id,
    ]);

    expect($service->canManageTeam($superAdmin, $team))->toBeTrue()
        ->and($service->canManageTeam($owner, $team))->toBeTrue()
        ->and($service->canManageTeam($outsider, $team))->toBeFalse()
        ->and($service->canManageTeam($member, $team))->toBeFalse()
        ->and($service->canManageTeam($teamAdmin, $team))->toBeTrue();
});

it('rejects renaming a system role', function (): void {
    $service = new RoleConfigurationService();

    $superAdmin = User::factory()->create();
    setPermissionsTeamId(null);
    $superAdmin->assignRole(RoleEnum::SuperAdmin->value);

    $globalAdminRole = SpatieRole::query()
        ->whereNull('team_id')
        ->where('guard_name', 'web')
        ->where('name', RoleEnum::Admin->value)
        ->firstOrFail();

    expect(fn () => $service->save($superAdmin, null, [
        'name' => 'renamed_admin',
        'permissions' => [],
    ], $globalAdminRole))->toThrow(ValidationException::class, 'System role names cannot be changed.');
});

it('creates and updates team-scoped roles and rejects duplicate names in the same scope', function (): void {
    $service = new RoleConfigurationService();

    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);

    $role = $service->save($owner, $team, [
        'name' => 'Account Manager',
        'permissions' => ['view_contact'],
    ]);

    expect((int) $role->team_id)->toBe($team->id)
        ->and($role->name)->toBe('Account Manager')
        ->and($role->hasPermissionTo('view_contact'))->toBeTrue();

    expect(fn () => $service->save($owner, $team, [
        'name' => 'account manager',
        'permissions' => [],
    ]))->toThrow(ValidationException::class, 'A role with this name already exists in this scope.');

    $updated = $service->save($owner, $team, [
        'name' => 'Account Manager',
        'permissions' => ['view_contact', 'create_contact'],
    ], $role);

    expect($updated->hasPermissionTo('create_contact'))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', [
        'team_id' => $team->id,
        'event' => 'team.role_configured',
    ]);
});

it('blocks deleting an assigned custom role until members are reassigned', function (): void {
    $service = new RoleConfigurationService();

    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $member = User::factory()->create();
    $team->users()->attach($member, ['role' => 'editor']);

    $teamRole = $service->save($owner, $team, [
        'name' => 'Support Specialist',
        'permissions' => ['view_contact'],
    ]);

    DB::table('model_has_roles')->insert([
        'role_id' => $teamRole->id,
        'model_type' => $member->getMorphClass(),
        'model_id' => $member->getKey(),
        'team_id' => $team->id,
    ]);

    expect(fn () => $service->delete($owner, $team, $teamRole))
        ->toThrow(ValidationException::class, 'Reassign members before deleting this role.');

    DB::table('model_has_roles')->where('role_id', $teamRole->id)->delete();

    $service->delete($owner, $team, $teamRole);

    $this->assertDatabaseMissing('roles', ['id' => $teamRole->id]);
    $this->assertDatabaseHas('audit_logs', [
        'team_id' => $team->id,
        'event' => 'team.role_deleted',
    ]);
});
