<?php

declare(strict_types=1);

uses(TestCase::class, RefreshDatabase::class);

use Filament\Actions\CreateAction;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\RolesPermissions\Contracts\PrivilegedActor;
use Liberu\Foundation\RolesPermissions\Enums\Role as RoleEnum;
use Liberu\Foundation\RolesPermissions\Services\RoleConfigurationService;
use Liberu\Foundation\RolesPermissionsFilament\Forms\RolePermissionBuilder;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages\CreateRole;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages\EditRole;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages\ListRoles;
use Liberu\Foundation\RolesPermissionsFilament\RolesPermissionsFilamentPlugin;
use Liberu\Foundation\RolesPermissionsFilament\RolesPermissionsFilamentServiceProvider;
use Spatie\Permission\Models\Role as SpatieRole;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

function makePrivilegedTestActor(bool $superAdmin): Authenticatable
{
    return new class($superAdmin) extends Authenticatable implements PrivilegedActor
    {
        public function __construct(private readonly bool $superAdmin)
        {
            parent::__construct();
        }

        public function authorizationIdentifier(): int|string
        {
            return 1;
        }

        public function authorizationType(): string
        {
            return 'test-actor';
        }

        public function hasRoleInAnyTeam(string|array $roles): bool
        {
            return $this->superAdmin;
        }

        public function isSuperAdmin(): bool
        {
            return $this->superAdmin;
        }
    };
}

it('covers RolesPermissionsFilamentPlugin, service provider, and RolePermissionBuilder', function (): void {
    $provider = $this->app->register(RolesPermissionsFilamentServiceProvider::class, force: true);
    expect($provider)->toBeInstanceOf(RolesPermissionsFilamentServiceProvider::class);

    $plugin = RolesPermissionsFilamentPlugin::make();
    expect($plugin)->toBeInstanceOf(RolesPermissionsFilamentPlugin::class);

    $platformComponents = RolePermissionBuilder::components(platform: true);
    $teamComponents = RolePermissionBuilder::components(platform: false);

    expect($platformComponents)->toHaveCount(2)
        ->and($teamComponents)->toHaveCount(2);
});

it('covers RoleResource authorization gates, eloquent query, form, table, and pages', function (): void {
    $globalCustom = SpatieRole::create([
        'name' => 'custom_auditor',
        'guard_name' => 'web',
        'team_id' => null,
    ]);
    $superAdminRole = SpatieRole::firstOrCreate([
        'name' => RoleEnum::SuperAdmin->value,
        'guard_name' => 'web',
        'team_id' => null,
    ]);
    $teamScopedRole = SpatieRole::create([
        'name' => 'team_editor',
        'guard_name' => 'web',
        'team_id' => 99,
    ]);

    // Guest / non-super-admin denied everywhere
    $this->actingAs(makePrivilegedTestActor(false));
    expect(RoleResource::canAccess())->toBeFalse()
        ->and(RoleResource::canViewAny())->toBeFalse()
        ->and(RoleResource::getViewAnyAuthorizationResponse()->allowed())->toBeFalse()
        ->and(RoleResource::canCreate())->toBeFalse()
        ->and(RoleResource::getCreateAuthorizationResponse()->allowed())->toBeFalse()
        ->and(RoleResource::canView($globalCustom))->toBeFalse()
        ->and(RoleResource::getViewAuthorizationResponse($globalCustom)->allowed())->toBeFalse()
        ->and(RoleResource::canEdit($globalCustom))->toBeFalse()
        ->and(RoleResource::getEditAuthorizationResponse($globalCustom)->allowed())->toBeFalse()
        ->and(RoleResource::canDelete($globalCustom))->toBeFalse()
        ->and(RoleResource::getDeleteAuthorizationResponse($globalCustom)->allowed())->toBeFalse()
        ->and(RoleResource::canDeleteAny())->toBeFalse()
        ->and(RoleResource::getDeleteAnyAuthorizationResponse()->allowed())->toBeFalse();

    // Super admin allowed on global custom role, restricted on built-in/team roles
    $this->actingAs(makePrivilegedTestActor(true));
    expect(RoleResource::canAccess())->toBeTrue()
        ->and(RoleResource::canViewAny())->toBeTrue()
        ->and(RoleResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
        ->and(RoleResource::canCreate())->toBeTrue()
        ->and(RoleResource::getCreateAuthorizationResponse()->allowed())->toBeTrue()
        ->and(RoleResource::canView($globalCustom))->toBeTrue()
        ->and(RoleResource::canView($teamScopedRole))->toBeFalse()
        ->and(RoleResource::getViewAuthorizationResponse($globalCustom)->allowed())->toBeTrue()
        ->and(RoleResource::canEdit($globalCustom))->toBeTrue()
        ->and(RoleResource::canEdit($superAdminRole))->toBeFalse()
        ->and(RoleResource::getEditAuthorizationResponse($globalCustom)->allowed())->toBeTrue()
        ->and(RoleResource::canDelete($globalCustom))->toBeTrue()
        ->and(RoleResource::canDelete($superAdminRole))->toBeFalse()
        ->and(RoleResource::getDeleteAuthorizationResponse($globalCustom)->allowed())->toBeTrue();

    $queryNames = RoleResource::getEloquentQuery()->pluck('name')->all();
    expect($queryNames)->toContain('custom_auditor')
        ->not->toContain('team_editor')
        ->and(array_keys(RoleResource::getPages()))->toBe(['index', 'create', 'edit']);

    $listPage = new ListRoles();
    expect($listPage->getSubheading())->toContain('Global definitions apply across teams');

    $headerActions = Closure::bind(fn (): array => $this->getHeaderActions(), $listPage, ListRoles::class)();
    expect($headerActions)->toHaveCount(1)
        ->and($headerActions[0])->toBeInstanceOf(CreateAction::class);

    $schema = RoleResource::form(Schema::make($listPage));
    expect($schema->getComponents())->toHaveCount(2);

    $table = RoleResource::table(Table::make($listPage));
    expect($table->getColumns())->toHaveCount(4);
});

it('covers CreateRole and EditRole record creation, form hydration, updates, and validation translation', function (): void {
    $superAdmin = makePrivilegedTestActor(true);
    $this->actingAs($superAdmin);

    $service = $this->mock(RoleConfigurationService::class);
    $createdRole = SpatieRole::create([
        'name' => 'finance_reviewer',
        'guard_name' => 'web',
        'team_id' => null,
    ]);

    $service->shouldReceive('save')
        ->once()
        ->with($superAdmin, null, ['name' => 'finance_reviewer', 'permissions' => []])
        ->andReturn($createdRole);

    $service->shouldReceive('save')
        ->once()
        ->with($superAdmin, null, ['name' => ''])
        ->andThrow(ValidationException::withMessages(['name' => ['The name field is required.']]));

    $service->shouldReceive('save')
        ->once()
        ->with($superAdmin, null, ['name' => 'finance_reviewer_v2'], $createdRole)
        ->andReturn($createdRole);

    $service->shouldReceive('save')
        ->once()
        ->with($superAdmin, null, ['name' => 'bad'], $createdRole)
        ->andThrow(ValidationException::withMessages(['name' => ['Invalid role name.']]));

    $createPage = new CreateRole();
    $saved = Closure::bind(
        fn (array $data) => $this->handleRecordCreation($data),
        $createPage,
        CreateRole::class
    )(['name' => 'finance_reviewer', 'permissions' => []]);
    expect($saved->is($createdRole))->toBeTrue();

    expect(fn () => Closure::bind(
        fn (array $data) => $this->handleRecordCreation($data),
        $createPage,
        CreateRole::class
    )(['name' => '']))->toThrow(ValidationException::class);

    $editPage = new EditRole();
    $editPage->record = $createdRole;

    $filled = Closure::bind(
        fn (array $data): array => $this->mutateFormDataBeforeFill($data),
        $editPage,
        EditRole::class
    )(['name' => 'finance_reviewer']);
    expect($filled['permissions'])->toBeArray();

    $updated = Closure::bind(
        fn ($record, array $data) => $this->handleRecordUpdate($record, $data),
        $editPage,
        EditRole::class
    )($createdRole, ['name' => 'finance_reviewer_v2']);
    expect($updated->is($createdRole))->toBeTrue();

    expect(fn () => Closure::bind(
        fn ($record, array $data) => $this->handleRecordUpdate($record, $data),
        $editPage,
        EditRole::class
    )($createdRole, ['name' => 'bad']))->toThrow(ValidationException::class);

    // Non-privileged actor is aborted with 403
    auth()->logout();
    expect(fn () => Closure::bind(
        fn (array $data) => $this->handleRecordCreation($data),
        $createPage,
        CreateRole::class
    )(['name' => 'x']))->toThrow(HttpException::class);
});
