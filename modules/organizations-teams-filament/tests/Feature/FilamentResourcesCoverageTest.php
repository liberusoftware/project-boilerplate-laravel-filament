<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Schemas\Schema as FilamentSchema;
use Filament\Tables\Table;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Contracts\OrganizationActor;
use Liberu\Foundation\Organizations\Jobs\GenerateTeamBackup;
use Liberu\Foundation\Organizations\Jobs\ImportTeamBackup;
use Liberu\Foundation\Organizations\Jobs\RestoreTeamBackup;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamBackup;
use Liberu\Foundation\Organizations\Services\TeamManagementService;
use Liberu\Foundation\Organizations\Services\TeamRestoreService;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamMemberResource;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamMemberResource\Pages\ListTeamMembers;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleLogResource;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleLogResource\Pages\ListTeamRoleLogs;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource\Pages\CreateTeamRole;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource\Pages\EditTeamRole;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource\Pages\ListTeamRoles;
use Liberu\Foundation\OrganizationsFilament\Http\Middleware\AssignDefaultTeam;
use Liberu\Foundation\OrganizationsFilament\Http\Middleware\SetFilamentDefaultTenant;
use Liberu\Foundation\OrganizationsFilament\OrganizationsFilamentPlugin;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamBackupResource;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamBackupResource\Pages\ListTeamBackups;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages\CreateTeam;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages\EditTeam;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages\ListTeams;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource\Pages\CreateTeamSubscription;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource\Pages\EditTeamSubscription;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource\Pages\ListTeamSubscriptions;
use Liberu\Foundation\OrganizationsFilament\Support\FilamentTeamJobNotifier;
use Liberu\Foundation\OrganizationsFilament\Tests\Fixtures\OrganizationUser;
use Liberu\Foundation\OrganizationsFilament\Tests\Fixtures\TestAuditLog;
use Liberu\Foundation\RolesPermissions\Enums\Role;
use Liberu\Foundation\RolesPermissionsFilament\Forms\RolePermissionBuilder;
use Liberu\PackageTestbench\TestUser;
use Spatie\Permission\Models\Role as SpatieRole;

function createFilamentUser(array $overrides = []): OrganizationUser
{
    static $seq = 0;
    $seq++;

    return OrganizationUser::forceCreate(array_merge([
        'name' => "Filament User {$seq}",
        'email' => "fil-user-{$seq}-".uniqid().'@example.test',
        'password' => bcrypt('Password123!@#'),
    ], $overrides));
}

function createFilamentTeam(OrganizationUser $owner, array $overrides = []): Team
{
    return Team::forceCreate(array_merge([
        'user_id' => $owner->id,
        'name' => 'Filament Team '.uniqid(),
        'personal_team' => false,
    ], $overrides));
}

function invokeProtectedMethod(object $instance, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($instance, $method);

    return $ref->invokeArgs($instance, $args);
}

afterEach(function () {
    Auth::logout();
    Filament::setTenant(null);
    config()->set('organizations-teams.audit_log_model', null);
});

it('covers AssignDefaultTeam, SetFilamentDefaultTenant, FilamentTeamJobNotifier, and plugin', function () {
    $next = fn (Request $req): Response => new Response('ok', 200);
    $req = Request::create('/test', 'GET');

    Filament::setTenant(null);
    $mw = new AssignDefaultTeam();
    expect($mw->handle($req, $next)->getStatusCode())->toBe(200)
        ->and(Filament::getTenant())->toBeNull();

    $userNoTeam = createFilamentUser();
    Auth::login($userNoTeam);
    $mw->handle($req, $next);
    expect(Filament::getTenant())->toBeInstanceOf(Team::class)
        ->and($userNoTeam->fresh()->current_team_id)->not->toBeNull();

    Filament::setTenant(null);
    $mw->handle($req, $next);
    expect(Filament::getTenant()?->id)->toBe($userNoTeam->fresh()->current_team_id);

    Filament::setTenant(null);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $defaultTenantMw = new SetFilamentDefaultTenant();
    expect($defaultTenantMw->handle($req, $next)->getStatusCode())->toBe(200)
        ->and($defaultTenantMw->handle($req, $next)->getStatusCode())->toBe(200);

    $notifier = new FilamentTeamJobNotifier();
    $notifier->notify($userNoTeam, 'Done', 'All good', true);
    $notifier->notify($userNoTeam, 'Failed', 'Something broke', false);
    expect($userNoTeam->notifications()->count())->toBe(2);

    $plugin = OrganizationsFilamentPlugin::make();
    expect($plugin->getId())->toBe('liberu-organizations');
});

it('covers TeamMemberResource and ListTeamMembers page', function () {
    $owner = createFilamentUser();
    $member = createFilamentUser();
    $extra = createFilamentUser();
    $team = createFilamentTeam($owner);
    $team->users()->attach($member, ['role' => 'member']);

    $customRole = SpatieRole::create(['name' => 'fil_custom_role', 'guard_name' => 'web', 'team_id' => $team->id]);

    expect(TeamMemberResource::permissionResource())->toBe('team_member')
        ->and(TeamMemberResource::canViewAny())->toBeFalse()
        ->and(TeamMemberResource::getViewAnyAuthorizationResponse()->denied())->toBeTrue()
        ->and(TeamMemberResource::getEloquentQuery()->count())->toBe(0)
        ->and(TeamMemberResource::getPages())->toHaveKey('index');

    Auth::login($owner);
    Filament::setTenant($team);
    setPermissionsTeamId($team->id);

    expect(TeamMemberResource::canViewAny())->toBeTrue()
        ->and(TeamMemberResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
        ->and(TeamMemberResource::getEloquentQuery()->pluck('id')->all())->toContain($owner->id, $member->id)
        ->and(TeamMemberResource::assignableRoles())->toHaveKeys(['manager', 'sales_rep', 'free', 'fil_custom_role']);

    $page = new ListTeamMembers();
    $table = TeamMemberResource::table(Table::make($page));
    (new ReflectionProperty($page, 'table'))->setValue($page, $table);

    $roleColumn = $table->getColumns()['team_role'];
    expect($roleColumn->record($member)->getState())->toBe('—');

    $recordActions = collect($table->getRecordActions())->keyBy(fn ($a) => $a->getName());

    $changeRole = $recordActions['changeRole'];
    expect($changeRole->record($owner)->isVisible())->toBeFalse()
        ->and($changeRole->record($member)->isVisible())->toBeTrue();

    $changeRole->record($member)->evaluate($changeRole->getActionFunction(), [
        'record' => $member,
        'data' => ['role' => Role::Manager->value],
        'service' => new TeamManagementService(),
    ]);
    expect($member->fresh()->hasRole(Role::Manager->value))->toBeTrue();

    $changeRole->record($member)->evaluate($changeRole->getActionFunction(), [
        'record' => $member,
        'data' => ['role' => 'fil_custom_role'],
        'service' => new TeamManagementService(),
    ]);
    expect($member->fresh()->hasRole('fil_custom_role'))->toBeTrue();

    $bulkActions = collect($table->getToolbarActions())->keyBy(fn ($a) => $a->getName());
    $setRole = $bulkActions['setRole'];
    $setRole->evaluate($setRole->getActionFunction(), [
        'records' => Collection::make([$owner, $member]),
        'data' => ['role' => Role::SalesRep->value],
        'service' => new TeamManagementService(),
    ]);
    expect($member->fresh()->hasRole(Role::SalesRep->value))->toBeTrue();

    $setRole->evaluate($setRole->getActionFunction(), [
        'records' => Collection::make([$member, createFilamentUser()]),
        'data' => ['role' => 'fil_custom_role'],
        'service' => new TeamManagementService(),
    ]);
    expect($member->fresh()->hasRole('fil_custom_role'))->toBeTrue();

    $removeMember = $recordActions['removeMember'];
    expect($removeMember->record($owner)->isVisible())->toBeFalse()
        ->and($removeMember->record($member)->isVisible())->toBeTrue();

    $removeMember->record($member)->evaluate($removeMember->getActionFunction(), [
        'record' => $member,
        'service' => new TeamManagementService(),
    ]);
    expect($team->users()->whereKey($member->id)->exists())->toBeFalse();

    $headerActions = invokeProtectedMethod($page, 'getHeaderActions');
    $addMember = $headerActions[0];

    $addMember->evaluate($addMember->getActionFunction(), [
        'data' => ['email' => $extra->email, 'password' => null, 'role' => Role::Free->value],
        'service' => new TeamManagementService(),
    ]);
    expect($team->users()->whereKey($extra->id)->exists())->toBeTrue();

    $addMember->evaluate($addMember->getActionFunction(), [
        'data' => ['name' => 'No Pass', 'email' => 'nopass@example.test', 'password' => null, 'role' => Role::Free->value],
        'service' => new TeamManagementService(),
    ]);

    $addMember->evaluate($addMember->getActionFunction(), [
        'data' => ['name' => 'Brand New', 'email' => 'brandnew@example.test', 'password' => 'SuperSecret123!@#', 'role' => Role::Manager->value],
        'service' => new TeamManagementService(),
    ]);
    expect(OrganizationUser::where('email', 'brandnew@example.test')->exists())->toBeTrue();

    Filament::setTenant(null);
    $owner->forceFill(['current_team_id' => null])->save();
    expect(TeamMemberResource::assignableRoles())->toHaveKeys(['manager', 'sales_rep', 'free']);
    $changeRole->record($member)->evaluate($changeRole->getActionFunction(), [
        'record' => $member,
        'data' => ['role' => Role::Free->value],
        'service' => new TeamManagementService(),
    ]);
    $setRole->evaluate($setRole->getActionFunction(), [
        'records' => Collection::make([$member]),
        'data' => ['role' => Role::Free->value],
        'service' => new TeamManagementService(),
    ]);
    $addMember->evaluate($addMember->getActionFunction(), [
        'data' => ['email' => 'x@example.test', 'password' => 'SuperSecret123!@#', 'role' => Role::Free->value],
        'service' => new TeamManagementService(),
    ]);
});

it('covers TeamRoleLogResource and ListTeamRoleLogs page', function () {
    $owner = createFilamentUser();
    $team = createFilamentTeam($owner);

    expect(TeamRoleLogResource::getModel())->toBe(Team::class)
        ->and(TeamRoleLogResource::permissionResource())->toBe('team_role_log')
        ->and(TeamRoleLogResource::canAccess())->toBeFalse()
        ->and(TeamRoleLogResource::canViewAny())->toBeFalse()
        ->and(TeamRoleLogResource::getViewAnyAuthorizationResponse()->denied())->toBeTrue()
        ->and(TeamRoleLogResource::canCreate())->toBeFalse()
        ->and(TeamRoleLogResource::getCreateAuthorizationResponse()->denied())->toBeTrue()
        ->and(TeamRoleLogResource::getPages())->toHaveKey('index');

    config()->set('organizations-teams.audit_log_model', TestAuditLog::class);

    Auth::login($owner);
    Filament::setTenant($team);

    expect(TeamRoleLogResource::getModel())->toBe(TestAuditLog::class)
        ->and(TeamRoleLogResource::canAccess())->toBeTrue()
        ->and(TeamRoleLogResource::canViewAny())->toBeTrue()
        ->and(TeamRoleLogResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
        ->and(TeamRoleLogResource::getEloquentQuery()->toSql())->toContain('team_id');

    $page = new ListTeamRoleLogs();
    $table = TeamRoleLogResource::table(Table::make($page));
    expect($table->getColumns())->toHaveKeys(['created_at', 'user.name', 'action', 'description'])
        ->and(invokeProtectedMethod($page, 'getHeaderActions'))->toBe([]);
});

it('covers TeamRoleResource and its List, Create, and Edit pages', function () {
    $owner = createFilamentUser();
    $team = createFilamentTeam($owner);
    $customRole = SpatieRole::create(['name' => 'team_editor_custom', 'guard_name' => 'web', 'team_id' => $team->id]);
    $systemRole = SpatieRole::firstOrCreate(['name' => Role::Admin->value, 'guard_name' => 'web', 'team_id' => null]);

    expect(TeamRoleResource::permissionResource())->toBe('team_role')
        ->and(TeamRoleResource::team())->toBeNull()
        ->and(TeamRoleResource::canAccess())->toBeFalse()
        ->and(TeamRoleResource::canViewAny())->toBeFalse()
        ->and(TeamRoleResource::getViewAnyAuthorizationResponse()->denied())->toBeTrue()
        ->and(TeamRoleResource::canCreate())->toBeFalse()
        ->and(TeamRoleResource::getCreateAuthorizationResponse()->denied())->toBeTrue()
        ->and(TeamRoleResource::canView($customRole))->toBeFalse()
        ->and(TeamRoleResource::getViewAuthorizationResponse($customRole)->denied())->toBeTrue()
        ->and(TeamRoleResource::canEdit($customRole))->toBeFalse()
        ->and(TeamRoleResource::getEditAuthorizationResponse($customRole)->denied())->toBeTrue()
        ->and(TeamRoleResource::canDelete($customRole))->toBeFalse()
        ->and(TeamRoleResource::getDeleteAuthorizationResponse($customRole)->denied())->toBeTrue()
        ->and(TeamRoleResource::canDeleteAny())->toBeFalse()
        ->and(TeamRoleResource::getDeleteAnyAuthorizationResponse()->denied())->toBeTrue()
        ->and(TeamRoleResource::getEloquentQuery()->count())->toBe(0)
        ->and(TeamRoleResource::grantablePermissions())->toBeArray()
        ->and(TeamRoleResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    Auth::login($owner);
    Filament::setTenant($team);

    expect(TeamRoleResource::team()?->id)->toBe($team->id)
        ->and(TeamRoleResource::canAccess())->toBeTrue()
        ->and(TeamRoleResource::canViewAny())->toBeTrue()
        ->and(TeamRoleResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
        ->and(TeamRoleResource::canCreate())->toBeTrue()
        ->and(TeamRoleResource::getCreateAuthorizationResponse()->allowed())->toBeTrue()
        ->and(TeamRoleResource::canView($customRole))->toBeTrue()
        ->and(TeamRoleResource::getViewAuthorizationResponse($customRole)->allowed())->toBeTrue()
        ->and(TeamRoleResource::canEdit($customRole))->toBeTrue()
        ->and(TeamRoleResource::getEditAuthorizationResponse($customRole)->allowed())->toBeTrue()
        ->and(TeamRoleResource::canDelete($customRole))->toBeTrue()
        ->and(TeamRoleResource::getDeleteAuthorizationResponse($customRole)->allowed())->toBeTrue()
        ->and(TeamRoleResource::canDelete($systemRole))->toBeFalse()
        ->and(TeamRoleResource::getEloquentQuery()->pluck('id')->all())->toContain($customRole->id);

    $listPage = new ListTeamRoles();
    expect(invokeProtectedMethod($listPage, 'getHeaderActions'))->toHaveCount(1);

    $formSchema = TeamRoleResource::form(FilamentSchema::make($listPage));
    expect($formSchema->getComponents())->not->toBeEmpty();

    if (! class_exists(RolePermissionBuilder::class)) {
        eval('namespace Liberu\\Foundation\\RolesPermissionsFilament\\Forms; class RolePermissionBuilder { public static function components(): array { return [\\Filament\\Forms\\Components\\TextInput::make("name")]; } }');
    }
    expect(TeamRoleResource::form(FilamentSchema::make($listPage))->getComponents())->toHaveCount(1);

    $table = TeamRoleResource::table(Table::make($listPage));
    expect($table->getColumns())->toHaveKeys(['name', 'permissions_count', 'updated_at']);

    $createPage = new CreateTeamRole();
    $createdRole = invokeProtectedMethod($createPage, 'handleRecordCreation', [[
        'name' => 'Support Lead',
        'permissions' => [],
    ]]);
    expect($createdRole)->toBeInstanceOf(SpatieRole::class)
        ->and($createdRole->name)->toBe('Support Lead');

    expect(fn () => invokeProtectedMethod($createPage, 'handleRecordCreation', [[
        'name' => 'admin',
        'permissions' => [],
    ]]))->toThrow(ValidationException::class);

    $editPage = new EditTeamRole();
    $editPage->record = $createdRole;
    $filled = invokeProtectedMethod($editPage, 'mutateFormDataBeforeFill', [['name' => 'Support Lead']]);
    expect($filled)->toHaveKey('permissions');

    $updatedRole = invokeProtectedMethod($editPage, 'handleRecordUpdate', [$createdRole, [
        'name' => 'Support Lead Updated',
        'permissions' => [],
    ]]);
    expect($updatedRole->name)->toBe('Support Lead Updated');

    expect(fn () => invokeProtectedMethod($editPage, 'handleRecordUpdate', [$createdRole, [
        'name' => 'super_admin',
        'permissions' => [],
    ]]))->toThrow(ValidationException::class);

    $deleteAction = collect($table->getRecordActions())->first(fn ($a) => $a->getName() === 'delete');
    $refUsing = new ReflectionProperty($deleteAction, 'using');
    $usingClosure = $refUsing->getValue($deleteAction);
    expect($usingClosure($updatedRole))->toBeTrue()
        ->and(SpatieRole::find($updatedRole->id))->toBeNull();
});

it('covers TeamBackupResource and ListTeamBackups page', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = createFilamentUser(['email' => 'ops-admin@example.test']);
    setPermissionsTeamId(null);
    $admin->assignRole(SpatieRole::firstOrCreate(['name' => Role::SuperAdmin->value, 'guard_name' => 'web', 'team_id' => null]));
    $team = createFilamentTeam($admin);

    expect(TeamBackupResource::isScopedToTenant())->toBeFalse()
        ->and(TeamBackupResource::canAccess())->toBeFalse()
        ->and(TeamBackupResource::getRouteMiddleware(Filament::getPanel('admin')))->toBe([SetFilamentDefaultTenant::class])
        ->and(TeamBackupResource::getPages())->toHaveKey('index');

    Auth::login($admin);
    expect(TeamBackupResource::canAccess())->toBeTrue();

    $customAdmin = new class() extends TestUser
    {
        public function isAdmin(): bool
        {
            return true;
        }
    };
    Auth::setUser($customAdmin);
    expect(TeamBackupResource::canAccess())->toBeTrue();
    Auth::login($admin);

    $page = new ListTeamBackups();
    expect(TeamBackupResource::form(FilamentSchema::make($page))->getComponents())->toBe([]);

    $table = TeamBackupResource::table(Table::make($page));
    $columns = $table->getColumns();
    expect($columns)->toHaveKeys(['team.name', 'status', 'size_bytes', 'created_at']);

    $statusCol = $columns['status'];
    foreach (['completed' => 'success', 'failed' => 'danger', 'processing' => 'warning', 'pending' => 'gray'] as $state => $color) {
        expect($statusCol->getColor($state))->toBe($color);
    }

    $sizeCol = $columns['size_bytes'];
    expect($sizeCol->formatState(2048))->toBe('2.0 KB')
        ->and($sizeCol->formatState(null))->toBe('—');

    Storage::disk('local')->put('backups/b1.zip', 'zipdata');
    $backup = TeamBackup::create([
        'team_id' => $team->id,
        'disk' => 'local',
        'path' => 'backups/b1.zip',
        'size_bytes' => 7,
        'status' => 'completed',
    ]);

    $actions = collect($table->getRecordActions())->keyBy(fn ($a) => $a->getName());
    expect($actions['download']->record($backup)->isVisible())->toBeTrue()
        ->and($actions['restore']->record($backup)->isVisible())->toBeTrue();

    $actions['restore']->record($backup)->evaluate($actions['restore']->getActionFunction(), ['record' => $backup]);
    Queue::assertPushed(RestoreTeamBackup::class);

    app()->instance(TeamRestoreService::class, new class() extends TeamRestoreService
    {
        public function firstPopulatedTable(Team $team): ?string
        {
            return 'populated_items';
        }
    });
    $actions['restore']->record($backup)->evaluate($actions['restore']->getActionFunction(), ['record' => $backup]);

    $deleteAction = $actions['delete'];
    $beforeRef = new ReflectionProperty($deleteAction, 'before');
    $beforeClosure = $beforeRef->getValue($deleteAction);
    $deleteAction->record($backup)->evaluate($beforeClosure, ['record' => $backup]);
    Storage::disk('local')->assertMissing('backups/b1.zip');

    $generate = invokeProtectedMethod($page, 'getHeaderActions')[0];
    $generate->evaluate($generate->getActionFunction(), ['data' => ['team_id' => $team->id]]);
    Queue::assertPushed(GenerateTeamBackup::class);
});

it('covers TeamResource and ListTeams, CreateTeam, EditTeam pages', function () {
    Queue::fake();

    $superAdmin = createFilamentUser();
    setPermissionsTeamId(null);
    $saRole = SpatieRole::firstOrCreate(['name' => Role::SuperAdmin->value, 'guard_name' => 'web', 'team_id' => null]);
    $superAdmin->assignRole($saRole);

    $team = createFilamentTeam($superAdmin, ['name' => 'Lifecycle Team', 'personal_team' => false]);
    $personal = createFilamentTeam($superAdmin, ['name' => 'Personal', 'personal_team' => true]);

    expect(TeamResource::isScopedToTenant())->toBeFalse()
        ->and(TeamResource::canAccess())->toBeFalse()
        ->and(TeamResource::getRouteMiddleware(Filament::getPanel('admin')))->toBe([SetFilamentDefaultTenant::class])
        ->and(TeamResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(new CreateTeam())->toBeInstanceOf(CreateTeam::class)
        ->and(new EditTeam())->toBeInstanceOf(EditTeam::class);

    Auth::login($superAdmin);
    expect(TeamResource::canAccess())->toBeTrue();

    $plainUser = new class() extends TestUser implements OrganizationActor
    {
        public function belongsToTeam($team): bool
        {
            return false;
        }

        public function ownsTeam($team): bool
        {
            return false;
        }
    };
    Auth::setUser($plainUser);
    expect(TeamResource::canAccess())->toBeTrue();
    Auth::login($superAdmin);

    $page = new ListTeams();
    expect(TeamResource::form(FilamentSchema::make($page))->getComponents())->toHaveCount(3);

    $table = TeamResource::table(Table::make($page));
    (new ReflectionProperty($page, 'table'))->setValue($page, $table);
    $statusCol = $table->getColumns()['status'];
    expect($statusCol->record($team)->getState())->toBe('Active')
        ->and($statusCol->getColor('Active'))->toBe('success')
        ->and($statusCol->getColor('Archived'))->toBe('danger');

    $actions = collect($table->getRecordActions())->keyBy(fn ($a) => $a->getName());

    expect($actions['archive']->record($team)->isVisible())->toBeTrue()
        ->and($actions['archive']->record($personal)->isVisible())->toBeFalse()
        ->and($actions['restore']->record($team)->isVisible())->toBeFalse();

    $actions['archive']->record($team)->evaluate($actions['archive']->getActionFunction(), ['record' => $team]);
    $archivedTeam = $team->fresh();
    $statusCol->clearCachedState();
    expect($archivedTeam->isArchived())->toBeTrue()
        ->and($statusCol->record($archivedTeam)->getState())->toBe('Archived')
        ->and($actions['restore']->record($archivedTeam)->isVisible())->toBeTrue();

    $actions['restore']->record($team->fresh())->evaluate($actions['restore']->getActionFunction(), ['record' => $team->fresh()]);
    expect($team->fresh()->isArchived())->toBeFalse();

    $cloneSchema = $actions['clone']->record($team)->getSchema(FilamentSchema::make($page));
    $ownerField = collect($cloneSchema->getComponents())->first(fn ($c) => $c->getName() === 'owner_id');
    expect($ownerField->getOptions())->toHaveKey($superAdmin->id);

    $actions['clone']->record($team)->evaluate($actions['clone']->getActionFunction(), [
        'record' => $team,
        'data' => ['name' => 'Cloned Via Resource', 'owner_id' => $superAdmin->id],
    ]);
    expect(Team::where('name', 'Cloned Via Resource')->exists())->toBeTrue();

    $cloneSchema = $actions['clone']->record($team)->getSchema(FilamentSchema::make($page));
    $ownerField = collect($cloneSchema->getComponents())->first(fn ($c) => $c->getName() === 'owner_id');
    expect($ownerField->getOptions())->toHaveKey($superAdmin->id);

    $actions['clone']->record($team)->evaluate($actions['clone']->getActionFunction(), [
        'record' => $team,
        'data' => ['name' => 'Missing Owner', 'owner_id' => 999999],
    ]);

    $headerActions = invokeProtectedMethod($page, 'getHeaderActions');
    $import = collect($headerActions)->first(fn ($a) => $a->getName() === 'import');
    $import->evaluate($import->getActionFunction(), [
        'data' => ['file' => 'imports/sample.zip', 'name' => 'From UI'],
    ]);
    Queue::assertPushed(ImportTeamBackup::class);
});

it('covers TeamSubscriptionResource and its List, Create, and Edit pages', function () {
    $listPage = new ListTeamSubscriptions();
    $createPage = new CreateTeamSubscription();
    $editPage = new EditTeamSubscription();

    $form = TeamSubscriptionResource::form(FilamentSchema::make($listPage));
    $table = TeamSubscriptionResource::table(Table::make($listPage));

    expect($form->getComponents())->toHaveCount(7)
        ->and($table->getColumns())->toHaveKeys(['team.name', 'stripe_status', 'quantity', 'trial_ends_at', 'ends_at', 'created_at'])
        ->and($table->getFilters())->toHaveKey('stripe_status')
        ->and(TeamSubscriptionResource::getRelations())->toBe([])
        ->and(TeamSubscriptionResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(invokeProtectedMethod($listPage, 'getHeaderActions'))->toHaveCount(1)
        ->and(invokeProtectedMethod($editPage, 'getHeaderActions'))->toHaveCount(1)
        ->and($createPage)->toBeInstanceOf(CreateTeamSubscription::class);
});
