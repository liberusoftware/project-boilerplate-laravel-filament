<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Foundation\RolesPermissions\Enums\Role as RoleEnum;
use Liberu\Foundation\RolesPermissions\Support\PermissionCatalog;
use Liberu\Foundation\RolesPermissionsFilament\Forms\RolePermissionBuilder;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages\CreateRole;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages\EditRole;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages\ListRoles;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['permissions' => [
        'actions' => ['view', 'create', 'update', 'delete'],
        'groups' => [
            'core' => ['contact', 'company', 'lead', 'deal', 'opportunity', 'task', 'note'],
            'advertising' => ['campaign'],
            'settings' => ['team_role'],
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

    $panel = Filament::getPanel('admin');
    if (! in_array(RoleResource::class, $panel->getResources(), true)) {
        $panel->resources([RoleResource::class]);
    }
    Filament::setCurrentPanel($panel);
});

it('enforces authorization rules on RoleResource for super admins and regular users', function () {
    $superAdmin = User::factory()->create();
    setPermissionsTeamId(null);
    $superAdmin->assignRole(RoleEnum::SuperAdmin->value);

    $regularUser = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $regularUser->id]);

    $superAdminRole = SpatieRole::whereNull('team_id')->where('guard_name', 'web')->where('name', RoleEnum::SuperAdmin->value)->firstOrFail();
    $adminRole = SpatieRole::whereNull('team_id')->where('guard_name', 'web')->where('name', RoleEnum::Admin->value)->firstOrFail();
    $customGlobalRole = SpatieRole::create(['name' => 'Custom Global', 'guard_name' => 'web', 'team_id' => null]);
    $teamRole = SpatieRole::create(['name' => 'Team Scoped', 'guard_name' => 'web', 'team_id' => $team->id]);

    // Regular user denied everywhere.
    $this->actingAs($regularUser);
    expect(RoleResource::canAccess())->toBeFalse()
        ->and(RoleResource::getViewAnyAuthorizationResponse()->allowed())->toBeFalse()
        ->and(RoleResource::getCreateAuthorizationResponse()->allowed())->toBeFalse()
        ->and(RoleResource::getViewAuthorizationResponse($customGlobalRole)->allowed())->toBeFalse()
        ->and(RoleResource::getEditAuthorizationResponse($customGlobalRole)->allowed())->toBeFalse()
        ->and(RoleResource::getDeleteAuthorizationResponse($customGlobalRole)->allowed())->toBeFalse()
        ->and(RoleResource::canDeleteAny())->toBeFalse()
        ->and(RoleResource::getDeleteAnyAuthorizationResponse()->allowed())->toBeFalse();

    // Super admin allowed on global web roles, except editing super_admin and deleting system roles.
    $this->actingAs($superAdmin);
    expect(RoleResource::canAccess())->toBeTrue()
        ->and(RoleResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
        ->and(RoleResource::getCreateAuthorizationResponse()->allowed())->toBeTrue()
        ->and(RoleResource::getViewAuthorizationResponse($customGlobalRole)->allowed())->toBeTrue()
        ->and(RoleResource::getViewAuthorizationResponse($teamRole)->allowed())->toBeFalse()
        ->and(RoleResource::getEditAuthorizationResponse($customGlobalRole)->allowed())->toBeTrue()
        ->and(RoleResource::getEditAuthorizationResponse($superAdminRole)->allowed())->toBeFalse()
        ->and(RoleResource::getDeleteAuthorizationResponse($customGlobalRole)->allowed())->toBeTrue()
        ->and(RoleResource::getDeleteAuthorizationResponse($adminRole)->allowed())->toBeFalse()
        ->and(RoleResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(RolePermissionBuilder::components(platform: false))->toHaveCount(2);
});

it('lists, creates, edits, validates, and deletes global roles through RoleResource pages', function () {
    $superAdmin = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $superAdmin->id]);
    setPermissionsTeamId(null);
    $superAdmin->assignRole(RoleEnum::SuperAdmin->value);
    $this->actingAs($superAdmin);
    Filament::setTenant($team);

    $existing = SpatieRole::create(['name' => 'Support Lead', 'guard_name' => 'web', 'team_id' => null]);
    $existing->syncPermissions(['view_contact', 'delete_contact']);

    // ListRoles page renders table, header actions, subheading, and executes delete action.
    $listTest = Livewire::test(ListRoles::class)
        ->assertOk()
        ->assertSee('Support Lead')
        ->assertSee('Global definitions apply across teams.')
        ->assertActionExists('create');

    // CreateRole page: apply preset, trigger validation error on reserved name, then create valid role.
    Livewire::test(CreateRole::class)
        ->assertOk()
        ->set('data.preset', 'sales')
        ->fillForm([
            'name' => 'admin',
            'permissions' => ['view_contact'],
        ])
        ->call('create')
        ->assertHasFormErrors(['name'])
        ->fillForm([
            'name' => 'Compliance Reviewer',
            'permissions' => ['view_contact', 'delete_contact'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = SpatieRole::whereNull('team_id')->where('name', 'Compliance Reviewer')->firstOrFail();
    expect($created->hasPermissionTo('view_contact'))->toBeTrue()
        ->and($created->hasPermissionTo('delete_contact'))->toBeTrue();

    // EditRole page: loads existing permissions, maps validation error, and saves valid update.
    Livewire::test(EditRole::class, ['record' => $created->getKey()])
        ->assertOk()
        ->fillForm([
            'name' => 'admin',
            'permissions' => ['view_contact'],
        ])
        ->call('save')
        ->assertHasFormErrors(['name'])
        ->fillForm([
            'name' => 'Compliance Reviewer Updated',
            'permissions' => ['view_contact'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($created->fresh()->name)->toBe('Compliance Reviewer Updated');

    // Delete via RoleResource table action.
    $listTest->callTableAction('delete', $created)
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseMissing('roles', ['id' => $created->id]);
});
