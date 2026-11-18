<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/modules/theme-support/tests/Fixtures/ComposerAvailability.php';

use App\Models\User;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Composer\InstalledVersions;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Panel;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Auth\User as FoundationUser;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\ViewFinderInterface;
use JoelButcher\Socialstream\Contracts\CreatesConnectedAccounts;
use JoelButcher\Socialstream\Features as SocialstreamFeatures;
use JoelButcher\Socialstream\RefreshedCredentials;
use JoelButcher\Socialstream\Socialstream;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Features as JetstreamFeatures;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\One\User as OAuthOneUser;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as OAuthTwoUser;
use Liberu\Foundation\Audit\Contracts\AuditRecorder;
use Liberu\Foundation\Audit\Support\AuditContext;
use Liberu\Foundation\Audit\Support\ModelAuditListener;
use Liberu\Foundation\Identity\Socialstream\Actions\CreateConnectedAccount;
use Liberu\Foundation\Identity\Socialstream\Actions\CreateUserFromProvider;
use Liberu\Foundation\Identity\Socialstream\Actions\GenerateRedirectForProvider;
use Liberu\Foundation\Identity\Socialstream\Actions\HandleInvalidState;
use Liberu\Foundation\Identity\Socialstream\Actions\ResolveSocialiteUser;
use Liberu\Foundation\Identity\Socialstream\Actions\SetUserPassword;
use Liberu\Foundation\Identity\Socialstream\Actions\UpdateConnectedAccount;
use Liberu\Foundation\Identity\Socialstream\Models\ConnectedAccount;
use Liberu\Foundation\Identity\Socialstream\Policies\ConnectedAccountPolicy;
use Liberu\Foundation\Identity\Socialstream\Providers\SocialstreamServiceProvider;
use Liberu\Foundation\Identity\Support\IdentifierNormalizer;
use Liberu\Foundation\IdentityFilament\Resources\UserResource\Pages\EditUser;
use Liberu\Foundation\JetstreamBridge\Actions\Fortify\UpdateUserProfileInformation;
use Liberu\Foundation\JetstreamBridge\Actions\Jetstream\DeleteUser;
use Liberu\Foundation\JetstreamBridge\Providers\FortifyServiceProvider;
use Liberu\Foundation\Localization\MyMemory\MyMemoryServiceProvider;
use Liberu\Foundation\Localization\MyMemory\TranslationService;
use Liberu\Foundation\ModuleManager\Cache\RegistryCache;
use Liberu\Foundation\ModuleManager\Exceptions\DependencyResolutionFailed;
use Liberu\Foundation\ModuleManager\Exceptions\InvalidManifest;
use Liberu\Foundation\ModuleManager\Exceptions\ModuleActivationRefused;
use Liberu\Foundation\ModuleManager\Exceptions\RequiredModuleFailed;
use Liberu\Foundation\ModuleManager\Manifest;
use Liberu\Foundation\ModuleManager\ModuleActivations;
use Liberu\Foundation\ModuleManager\ModuleDiscovery;
use Liberu\Foundation\ModuleManager\ModuleInventory;
use Liberu\Foundation\ModuleManager\ModuleLoader;
use Liberu\Foundation\ModuleManager\ModuleManagement as Modules;
use Liberu\Foundation\ModuleManager\ModuleManagerServiceProvider;
use Liberu\Foundation\ModuleManager\ModuleRegistry;
use Liberu\Foundation\ModuleManager\ModuleValidationGuard;
use Liberu\Foundation\ModuleManager\ModuleValidator;
use Liberu\Foundation\ModuleManagerFilament\ModuleManagerFilamentPlugin;
use Liberu\Foundation\ModuleManagerFilament\Pages\ModuleManagement;
use Liberu\Foundation\Organizations\Actions\InviteMember;
use Liberu\Foundation\Organizations\Actions\TransferOwnership;
use Liberu\Foundation\Organizations\Models\Organization;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamInvitation;
use Liberu\Foundation\Organizations\Policies\TeamPolicy;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages\EditTeam;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Liberu\Foundation\RolesPermissions\Services\AnyTeamRoleLookup;
use Liberu\Foundation\Sessions\Queries\SessionReader;
use Liberu\Foundation\SessionsDevicesFilament\Pages\AccountSecurity;
use Liberu\Foundation\SessionsDevicesFilament\SessionsDevicesFilamentPlugin;
use Liberu\Foundation\Settings\Settings\SiteSettings;
use Liberu\Foundation\Theme\Cache\ThemeCache;
use Liberu\Foundation\Theme\Discovery\ThemeDiscovery;
use Liberu\Foundation\Theme\Exceptions\InvalidTheme;
use Liberu\Foundation\Theme\Manifests\ThemeManifest;
use Liberu\Foundation\Theme\Services\ThemeManager;
use Liberu\Foundation\Theme\Tests\Fixtures\ComposerAvailability;
use Liberu\Foundation\UsersRoles\Database\Factories\UserFactory;
use Liberu\Foundation\UsersRoles\DatabaseServiceProvider;
use Liberu\Foundation\UsersRoles\Policies\UserPolicy;
use Liberu\Foundation\UsersRoles\Services\UserRoleLookup;
use Liberu\Localization\Contracts\TranslationProvider;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class CoverageGoodProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('coverage.isolated.good', true);
    }
}

final class CoverageThrowingProvider extends ServiceProvider
{
    public function register(): void
    {
        throw new RuntimeException('provider exploded');
    }
}

final class CoverageManagedProvider extends ServiceProvider {}

final class CoverageTableFixtureProvider extends ServiceProvider {}

final class CoverageCachePayload
{
    public static bool $awakened = false;

    public function __wakeup(): void
    {
        self::$awakened = true;
    }
}

final class VerifiableUserStub extends User implements MustVerifyEmail
{
    protected $table = 'users';

    public bool $sentVerification = false;

    public function sendEmailVerificationNotification(): void
    {
        $this->sentVerification = true;
    }
}

function coverageIsolatedModule(string $root, array &$installed, string $name, array $manifest = [], ?string $raw = null, bool $isInstalled = true): void
{
    $dir = "{$root}/{$name}";
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents("{$dir}/module.json", $raw ?? json_encode($manifest + [
        'name' => $name, 'display_name' => $name, 'description' => 'Test', 'version' => '1.0.0', 'category' => 'capability',
        'provider' => CoverageGoodProvider::class, 'requires' => ['packages' => []], 'capabilities' => [],
        'features' => ['Test'], 'default_enabled' => true,
    ], JSON_THROW_ON_ERROR));
    file_put_contents("{$dir}/composer.json", json_encode([
        'name' => "local/{$name}", 'type' => 'liberu-module', 'extra' => ['liberu' => ['name' => $name]],
    ], JSON_THROW_ON_ERROR));

    if ($isInstalled) {
        $installed['versions']["local/{$name}"] = [
            'pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => null,
            'type' => 'liberu-module', 'install_path' => $dir, 'aliases' => [], 'dev_requirement' => false,
        ];
        InstalledVersions::reload($installed);
    }
}

function coverageIsolatedLoader(string $root, array $required = [], array $enabled = [], array $disabled = [], bool $cached = false, string $cachePath = ''): ModuleLoader
{
    return new ModuleLoader(new ModuleValidator(), new RegistryCache(), [$root], $enabled, $disabled, $required, $cached, $cachePath);
}

function coverageManagedModule(string $root, array &$composer, string $name, array $manifest = [], ?string $composerVersion = '1.0.0'): void
{
    $dir = "{$root}/{$name}";
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents("{$dir}/module.json", json_encode($manifest + [
        'name' => $name, 'display_name' => ucfirst($name), 'description' => 'Test', 'version' => '1.0.0', 'category' => 'capability',
        'provider' => CoverageManagedProvider::class, 'requires' => ['packages' => []], 'capabilities' => [],
        'features' => ['Test'], 'default_enabled' => false, 'module_type' => 'feature',
    ], JSON_THROW_ON_ERROR));
    file_put_contents("{$dir}/composer.json", json_encode(['name' => "local/{$name}", 'type' => 'liberu-module', 'extra' => ['liberu' => ['name' => $name]]], JSON_THROW_ON_ERROR));

    if ($composerVersion !== null) {
        $composer['versions']["local/{$name}"] = [
            'pretty_version' => $composerVersion, 'version' => ltrim($composerVersion, 'v').'.0', 'reference' => null,
            'type' => 'liberu-module', 'install_path' => $dir, 'aliases' => [], 'dev_requirement' => false,
        ];
        InstalledVersions::reload($composer);
    }
}

function coverageManagedInventory(string $root, array $required = [], array $enabled = [], array $disabled = []): ModuleInventory
{
    [$enabled, $disabled] = ModuleActivations::apply($enabled, $disabled, app(ModuleActivations::class)->all());

    return (new ModuleLoader(new ModuleValidator(), new RegistryCache(), [$root], $enabled, $disabled, $required))->load();
}

function coverageManagement(string $root, array $required = [], array $enabled = [], array $disabled = [], array $discovered = []): Modules
{
    $packages = new PackageManifest(new Filesystem(), base_path(), "{$root}/packages.php");
    $packages->manifest = ['local/discovered' => ['providers' => $discovered]];

    return new Modules(coverageManagedInventory($root, $required, $enabled, $disabled), app(ModuleActivations::class), new ModuleValidator(), $packages, $enabled, $disabled);
}

function coverageMakeManifest(array $overrides = [], ?string $package = null): Manifest
{
    $dir = sys_get_temp_dir().'/liberu-module-'.bin2hex(random_bytes(5));
    mkdir($dir, 0777, true);
    $data = array_replace([
        'name' => 'example', 'display_name' => 'Example', 'description' => 'Test',
        'version' => '1.0.0', 'category' => 'capability',
        'provider' => ServiceProvider::class,
        'requires' => ['packages' => [], 'capabilities' => []],
        'capabilities' => [], 'features' => ['Example feature'], 'default_enabled' => true,
    ], $overrides);
    file_put_contents($dir.'/module.json', json_encode($data, JSON_THROW_ON_ERROR));
    if ($package) {
        file_put_contents($dir.'/composer.json', json_encode(['name' => $package], JSON_THROW_ON_ERROR));
    }

    return Manifest::fromFile($dir.'/module.json');
}

function writeCoverageTheme(array $changes = [], bool $asset = true): string
{
    $dir = sys_get_temp_dir().'/liberu-theme-'.bin2hex(random_bytes(5));
    mkdir($dir, 0777, true);
    if ($asset) {
        file_put_contents($dir.'/theme.css', '');
    }
    $data = array_replace([
        'name' => 'covered', 'display_name' => 'Covered', 'version' => '1.0.0',
        'provider' => ServiceProvider::class, 'type' => 'shared', 'parent' => '',
        'optimized_for' => [], 'tested_with' => [], 'required_capabilities' => ['one'],
        'optional_capabilities' => ['two'], 'supports' => [],
        'assets' => ['css' => ['theme.css'], 'js' => []],
    ], $changes);
    foreach ($changes as $key => $value) {
        if ($value === null) {
            unset($data[$key]);
        }
    }
    file_put_contents($dir.'/theme.json', json_encode($data, JSON_THROW_ON_ERROR));

    return $dir.'/theme.json';
}

function coverageThemePackage(string $name): string
{
    $dir = dirname(writeCoverageTheme(['name' => $name]));
    file_put_contents($dir.'/composer.json', json_encode([
        'name' => 'liberusoftware/theme-'.$name,
        'type' => 'liberu-theme',
        'extra' => ['liberu' => ['name' => $name]],
    ], JSON_THROW_ON_ERROR));

    return $dir;
}

// =========================================================================
// 1. ModelAuditListener
// =========================================================================
it('covers ModelAuditListener across non-model, non-genealogy, unbound, and bound operations', function () {
    $listener = new ModelAuditListener();

    $listener->handle('eloquent.created: stdClass', ['not-a-model']);
    $listener->handle('eloquent.created: App\\Models\\User', [new User()]);

    $genealogyModel = new class() extends Model
    {
        protected $table = 'genealogy_people';

        protected $guarded = [];
    };
    $genealogyModel->forceFill(['id' => 42, 'team_id' => 9, 'name' => 'Ancestor'])->syncOriginal();

    // Unknown operation
    $listener->handle('eloquent.saved: GenealogyPerson', [$genealogyModel]);

    // Unbound AuditRecorder
    $originalRecorder = app()->bound(AuditRecorder::class) ? app(AuditRecorder::class) : null;
    app()->forgetInstance(AuditRecorder::class);
    app()->offsetUnset(AuditRecorder::class);
    $listener->handle('eloquent.created: GenealogyPerson', [$genealogyModel]);

    // Bound AuditRecorder with authenticated actor and headers
    $recorded = [];
    $recorder = new class($recorded) implements AuditRecorder
    {
        public function __construct(public array &$items) {}

        public function record(string $operation, string $auditableType, int|string|null $auditableId, array $before = [], array $after = [], ?AuditContext $context = null): void
        {
            $this->items[] = compact('operation', 'auditableType', 'auditableId', 'before', 'after', 'context');
        }
    };
    app()->instance(AuditRecorder::class, $recorder);

    $user = User::factory()->create();
    $this->actingAs($user);
    request()->headers->set('X-Request-ID', 'req-123');
    request()->headers->set('X-Correlation-ID', 'corr-456');

    $listener->handle('eloquent.created: GenealogyPerson', [$genealogyModel]);
    $genealogyModel->forceFill(['name' => 'Updated Ancestor']);
    $listener->handle('eloquent.updated: GenealogyPerson', [$genealogyModel]);
    $listener->handle('eloquent.deleted: GenealogyPerson', [$genealogyModel]);

    expect($recorded)->toHaveCount(3)
        ->and($recorded[0]['operation'])->toBe('genealogy_created')
        ->and($recorded[0]['before'])->toBe([])
        ->and($recorded[1]['operation'])->toBe('genealogy_updated')
        ->and($recorded[2]['operation'])->toBe('genealogy_deleted')
        ->and($recorded[2]['after'])->toBe([])
        ->and($recorded[0]['context']->tenantId)->toBe('9')
        ->and($recorded[0]['context']->requestId)->toBe('req-123')
        ->and($recorded[0]['context']->correlationId)->toBe('corr-456');

    if ($originalRecorder !== null) {
        app()->instance(AuditRecorder::class, $originalRecorder);
    }
});

// =========================================================================
// 2 & 3 & 4. Filament EditUser, EditTeam, AccountSecurity & Plugin
// =========================================================================
it('covers EditUser, EditTeam, AccountSecurity, and SessionsDevicesFilamentPlugin', function () {
    $editUser = new EditUser();
    $userMethod = new ReflectionMethod(EditUser::class, 'getHeaderActions');
    expect($userMethod->invoke($editUser))->toHaveCount(1);

    $editTeam = new EditTeam();
    $teamMethod = new ReflectionMethod(EditTeam::class, 'getHeaderActions');
    expect($teamMethod->invoke($editTeam))->toHaveCount(1);

    expect(AccountSecurity::canAccess())->toBeFalse();
    $user = User::factory()->create();
    $this->actingAs($user);
    expect(AccountSecurity::canAccess())->toBeTrue();

    DB::table(config('sessions-devices.table', 'sessions'))->insert([
        'id' => 'sess-other',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Symfony',
        'payload' => 'test',
        'last_activity' => time(),
    ]);

    $reader = new SessionReader();
    $page = new AccountSecurity();
    $page->mount($reader);
    expect($page->sessions)->toBeInstanceOf(Collection::class)->toHaveCount(1);

    $page->revoke('sess-other', $reader);
    expect($page->sessions)->toHaveCount(0);

    $plugin = SessionsDevicesFilamentPlugin::make();
    $panel = Panel::make()->id('security-test');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBe('liberu-sessions-devices')
        ->and($panel->getPages())->toContain(AccountSecurity::class);
});

// =========================================================================
// 5, 6, 7. JetstreamBridge: UpdateUserProfileInformation, DeleteUser, FortifyServiceProvider
// =========================================================================
it('covers UpdateUserProfileInformation for MustVerifyEmail and unchanged email', function () {
    $user = VerifiableUserStub::create([
        'name' => 'Initial Name',
        'email' => 'initial@example.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
    ]);

    $action = new UpdateUserProfileInformation(new IdentifierNormalizer());

    // Email unchanged -> else branch
    $action->update($user, [
        'name' => 'Updated Same Email',
        'email' => 'initial@example.test',
    ]);
    expect($user->fresh()->name)->toBe('Updated Same Email')
        ->and($user->sentVerification)->toBeFalse();

    // Email changed on MustVerifyEmail -> updateVerifiedUser branch
    $action->update($user, [
        'name' => 'Verified Changed',
        'email' => 'changed@example.test',
    ]);
    expect($user->sentVerification)->toBeTrue()
        ->and($user->fresh()->email)->toBe('changed@example.test')
        ->and($user->fresh()->email_verified_at)->toBeNull();
});

it('covers JetstreamBridge DeleteUser validation and team/account cleanup', function () {
    $deletesTeams = new class() implements DeletesTeams
    {
        public array $deleted = [];

        public function delete($team): void
        {
            $this->deleted[] = $team->id;
            $team->delete();
        }
    };

    $action = new DeleteUser($deletesTeams);
    expect(fn () => $action->delete('not-a-user'))
        ->toThrow(InvalidArgumentException::class, 'Only Laravel user models can be deleted.');

    $user = User::factory()->withPersonalTeam()->create();
    $otherOwner = User::factory()->create();
    $memberTeam = Team::forceCreate([
        'user_id' => $otherOwner->id,
        'name' => 'Shared Team',
        'personal_team' => false,
    ]);
    $user->teams()->attach($memberTeam, ['role' => 'editor']);

    $action->delete($user);

    expect($deletesTeams->deleted)->not->toBeEmpty()
        ->and(User::find($user->id))->toBeNull();
});

it('covers FortifyServiceProvider password defaults and rate limiters', function () {
    $provider = new FortifyServiceProvider(app());
    $provider->register();
    $provider->boot();

    expect(Password::default())->toBeInstanceOf(Password::class);

    $originalEnv = app()['env'];
    try {
        app()['env'] = 'production';
        expect(Password::default())->toBeInstanceOf(Password::class);
    } finally {
        app()['env'] = $originalEnv;
    }

    $request = Request::create('/login', 'POST', [
        'email' => 'User@Example.test',
        'credential' => ['id' => 'cred-123'],
    ]);
    $session = app('session.store');
    $session->put('login.id', 'user-42');
    $request->setLaravelSession($session);

    $loginLimiter = RateLimiter::limiter('login');
    $twoFactorLimiter = RateLimiter::limiter('two-factor');
    $passkeysLimiter = RateLimiter::limiter('passkeys');

    expect($loginLimiter($request))->not->toBeNull()
        ->and($twoFactorLimiter($request))->not->toBeNull()
        ->and($passkeysLimiter($request))->not->toBeNull();

    $requestWithoutCred = Request::create('/passkeys', 'POST');
    $requestWithoutCred->setLaravelSession($session);
    expect($passkeysLimiter($requestWithoutCred))->not->toBeNull();
});

// =========================================================================
// 8, 9, 10. Organizations, RolesPermissions, UsersRoles
// =========================================================================
it('covers Organizations Actions, Models, and TeamPolicy', function () {
    expect(fn () => (new InviteMember())->handle(1, 'invitee@example.test', 'invalid-role-xyz', 1))
        ->toThrow(InvalidArgumentException::class, 'The role cannot be assigned to a team member.');

    $owner = User::factory()->withPersonalTeam()->create();
    $outsider = User::factory()->create();
    $member = User::factory()->create();
    $team = $owner->ownedTeams()->first();

    $token = (new InviteMember())->handle($team->id, 'invitee@example.test', 'admin', $owner->id);
    expect($token)->toBeString()->not->toBeEmpty();

    expect(fn () => (new TransferOwnership())->handle($team, $owner->id, $outsider->id, false))
        ->toThrow(RuntimeException::class, 'Recent owner authentication is required.');

    expect(fn () => (new TransferOwnership())->handle($team, $owner->id, $outsider->id, true))
        ->toThrow(RuntimeException::class, 'The successor must be an active member.');

    DB::table('team_user')->insert([
        'team_id' => $team->id,
        'user_id' => $member->id,
        'role' => 'admin',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    (new TransferOwnership())->handle($team, $owner->id, $member->id, true);
    expect((int) $team->fresh()->user_id)->toBe($member->id);
    $team->forceFill(['user_id' => $owner->id])->save();

    $organization = new Organization(['name' => 'Org', 'slug' => 'org']);
    expect($organization->getCasts())->toHaveKey('archived_at', 'datetime')
        ->and($organization->teams())->toBeInstanceOf(HasMany::class);

    $invitation = new TeamInvitation(['email' => 'test@example.test']);
    expect($invitation->getCasts())->toHaveKeys(['expires_at', 'accepted_at', 'revoked_at'])
        ->and($invitation->team())->toBeInstanceOf(BelongsTo::class);

    $policy = new TeamPolicy();
    expect($policy->viewAny($owner))->toBeTrue()
        ->and($policy->view($owner, $team))->toBeTrue()
        ->and($policy->view($outsider, $team))->toBeFalse()
        ->and($policy->create($owner))->toBeTrue()
        ->and($policy->update($owner, $team))->toBeTrue()
        ->and($policy->addTeamMember($owner, $team))->toBeTrue()
        ->and($policy->updateTeamMember($owner, $team))->toBeTrue()
        ->and($policy->removeTeamMember($owner, $team))->toBeTrue()
        ->and($policy->delete($owner, $team))->toBeTrue();
});

it('covers RolesPermissions Role model and AnyTeamRoleLookup and UsersRoles classes', function () {
    $role = new Role();
    expect($role->team())->toBeInstanceOf(BelongsTo::class);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->first();

    $anyTeamLookup = new AnyTeamRoleLookup();
    expect($anyTeamLookup->hasRoleInAnyTeam($user, []))->toBeFalse();

    $createdRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web'], ['team_id' => $team->id]);
    DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))->insertOrIgnore([
        'role_id' => $createdRole->id,
        'model_type' => $user->authorizationType(),
        'model_id' => $user->authorizationIdentifier(),
        'team_id' => $team->id,
    ]);

    expect($anyTeamLookup->hasRoleInAnyTeam($user, 'super_admin'))->toBeTrue()
        ->and($user->authorizationIdentifier())->toBe($user->id)
        ->and($user->authorizationType())->toBe($user->getMorphClass())
        ->and($user->hasRoleInAnyTeam('super_admin'))->toBeTrue()
        ->and($user->isSuperAdmin())->toBeTrue();

    $userRoleLookup = new UserRoleLookup();
    expect($userRoleLookup->hasRoleInAnyTeam(new User(), 'super_admin'))->toBeFalse()
        ->and($userRoleLookup->hasRoleInAnyTeam($user, []))->toBeFalse()
        ->and($userRoleLookup->hasRoleInAnyTeam($user, 'super_admin'))->toBeTrue();

    $factory = UserFactory::new();
    expect($factory->modelName())->toBeString()
        ->and($factory->definition())->toHaveKeys(['name', 'email', 'password'])
        ->and($factory->unverified()->raw()['email_verified_at'])->toBeNull();

    (new DatabaseServiceProvider(app()))->boot();

    $userPolicy = new UserPolicy();
    Gate::before(fn () => true);
    foreach ([
        'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny',
        'restore', 'forceDelete', 'forceDeleteAny', 'restoreAny', 'replicate', 'reorder',
    ] as $method) {
        expect($userPolicy->{$method}($user))->toBeTrue();
    }
});

// =========================================================================
// 11. Localization MyMemory
// =========================================================================
it('covers MyMemoryServiceProvider and TranslationService completely', function () {
    (new MyMemoryServiceProvider(app()))->boot();

    $service = new TranslationService();
    expect($service)->toBeInstanceOf(TranslationProvider::class)
        ->and($service->name())->toBe('mymemory')
        ->and($service->translate('Hello', 'en', 'en'))->toBe('Hello')
        ->and($service->supportedLanguages())->toHaveKey('en')
        ->and($service->getSupportedLanguages())->toHaveKeys(['en', 'es', 'fr', 'de'])
        ->and($service->isLanguageSupported('en'))->toBeTrue()
        ->and($service->isLanguageSupported('xx'))->toBeFalse();

    Cache::flush();
    Http::swap(new HttpFactory());
    Http::fake([
        'api.mymemory.translated.net/*' => Http::response([
            'responseData' => ['translatedText' => 'Hola'],
        ], 200),
    ]);

    expect($service->translate('Hello', 'es', 'en'))->toBe('Hola')
        ->and($service->translate('Hello', 'es', 'en'))->toBe('Hola');

    Cache::put('translation:en:es:'.md5('Malformed'), ['unexpected' => true], 60);
    expect($service->translate('Malformed', 'es', 'en'))->toBe('Malformed');

    Cache::flush();
    Http::swap(new HttpFactory());
    Http::fake([
        'api.mymemory.translated.net/*' => Http::response(['responseData' => ['translatedText' => '']], 200),
    ]);
    expect($service->translate('Empty', 'es', 'en'))->toBe('Empty');

    Cache::flush();
    Http::swap(new HttpFactory());
    Http::fake([
        'api.mymemory.translated.net/*' => Http::response([], 500),
    ]);
    expect($service->translate('Fail500', 'es', 'en'))->toBe('Fail500');

    Cache::flush();
    Http::swap(new HttpFactory());
    Http::fake(function () {
        throw new ConnectionException('offline');
    });
    expect($service->translate('Offline', 'es', 'en'))->toBe('Offline');

    Cache::flush();
    Http::swap(new HttpFactory());
    Http::fake([
        'api.mymemory.translated.net/*' => Http::response([
            'responseData' => ['translatedText' => 'Traduit'],
        ], 200),
    ]);
    $batch = $service->translateBatch([
        'heading' => 'Hello',
        'nested' => [5 => 'World', 'count' => 0],
    ], 'fr', 'en');
    expect($batch)->toBe([
        'heading' => 'Traduit',
        'nested' => [5 => 'Traduit', 'count' => 0],
    ]);
});

// =========================================================================
// 12. Identity Socialstream
// =========================================================================
it('covers Identity Socialstream actions, models, policies, and provider', function () {
    (new SocialstreamServiceProvider(app()))->register();
    (new SocialstreamServiceProvider(app()))->boot();

    $owner = User::factory()->create(['name' => 'Owner', 'email' => 'owner@example.test']);
    $other = User::factory()->create(['name' => 'Other', 'email' => 'other@example.test']);

    foreach ([true, false] as $oauthTwo) {
        $providerUser = $oauthTwo ? OAuthTwoUser::fake() : OAuthOneUser::fake();
        $account = (new CreateConnectedAccount())->create($owner, 'GITHUB', $providerUser);
        expect($account)->toBeInstanceOf(ConnectedAccount::class)
            ->and($account->provider)->toBe('github')
            ->and(ConnectedAccount::factory()->make(['user_id' => $owner->id]))->toBeInstanceOf(ConnectedAccount::class);
    }

    expect(fn () => (new CreateConnectedAccount())->create(new stdClass(), 'github', OAuthTwoUser::fake()))
        ->toThrow(InvalidArgumentException::class, 'Eloquent user model')
        ->and(fn () => (new SetUserPassword())->set(new stdClass(), []))
        ->toThrow(InvalidArgumentException::class, 'Eloquent user models');

    config(['hashing.bcrypt.rounds' => 4]);
    $passwordAction = new SetUserPassword();
    expect(fn () => $passwordAction->set($owner, ['password' => 'valid-Pass123!', 'password_confirmation' => 'different']))
        ->toThrow(ValidationException::class);
    $passwordAction->set($owner, ['password' => 'Valid-Pass123!@#', 'password_confirmation' => 'Valid-Pass123!@#']);
    expect(Hash::check('Valid-Pass123!@#', $owner->fresh()->password))->toBeTrue();

    $account = (new CreateConnectedAccount())->create($owner, 'github', OAuthOneUser::fake());
    $policy = new ConnectedAccountPolicy();
    expect($policy->viewAny($owner))->toBeTrue()
        ->and($policy->create($owner))->toBeTrue();
    foreach (['view', 'update', 'delete'] as $op) {
        expect($policy->{$op}($owner, $account))->toBeTrue()
            ->and($policy->{$op}($other, $account))->toBeFalse();
    }

    expect(fn () => (new UpdateConnectedAccount())->update($other, $account, 'google', OAuthTwoUser::fake()))
        ->toThrow(AuthorizationException::class);

    $updated = (new UpdateConnectedAccount())->update($owner, $account, 'GOOGLE', OAuthTwoUser::fake(['name' => 'Updated']));
    expect($updated->provider)->toBe('google')->and($updated->name)->toBe('Updated');

    $updatedOne = (new UpdateConnectedAccount())->update($owner, $account, 'TWITTER', OAuthOneUser::fake(['name' => 'UpdatedOne']));
    expect($updatedOne->provider)->toBe('twitter')->and($updatedOne->expires_at)->toBeNull();

    $expiry = now()->addHours(2);
    Socialstream::refreshesTokensForProviderUsing('contract-provider', fn () => new RefreshedCredentials('renewed', 'renewed-secret', 'renewed-refresh', $expiry));
    $refreshAccount = (new CreateConnectedAccount())->create($owner, 'contract-provider', OAuthOneUser::fake());
    $refreshed = (new UpdateConnectedAccount())->updateRefreshToken($refreshAccount);
    expect($refreshed->fresh()->token)->toBe('renewed');

    $redirect = new RedirectResponse('https://provider.example.test/authorize');
    $socialUser = OAuthTwoUser::fake(['email' => null]);
    Socialite::shouldReceive('driver')->with('github')->andReturnSelf();
    Socialite::shouldReceive('redirect')->once()->andReturn($redirect);
    Socialite::shouldReceive('user')->andReturn($socialUser);

    expect((new GenerateRedirectForProvider())->generate('github'))->toBe($redirect);
    config(['socialstream.features' => []]);
    expect((new ResolveSocialiteUser())->resolve('github')->getEmail())->toBeNull();
    config(['socialstream.features' => [SocialstreamFeatures::generateMissingEmails()], 'app.domain' => '.example.test']);
    expect((new ResolveSocialiteUser())->resolve('github')->getEmail())->toBe('123456789@github.example.test');
    $socialUser->email = null;
    config(['app.domain' => null]);
    expect((new ResolveSocialiteUser())->resolve('github')->getEmail())->toBe('123456789@github');

    $error = new InvalidStateException('state mismatch');
    expect(fn () => (new HandleInvalidState())->handle($error))->toThrow($error);

    foreach ([true, false] as $avatars) {
        config([
            'socialstream.features' => $avatars ? [SocialstreamFeatures::providerAvatars()] : [],
            'jetstream.features' => [JetstreamFeatures::profilePhotos()],
        ]);
        $providerData = OAuthTwoUser::fake([
            'name' => 'Test Person',
            'email' => ($avatars ? 'avatar' : 'noavatar').'@example.test',
        ]);
        if ($avatars) {
            Http::fake(['*' => Http::response('fake-image-bytes', 200)]);
        }
        $createdActor = (new CreateUserFromProvider(new CreateConnectedAccount()))->create('github', $providerData);
        expect($createdActor->email_verified_at)->not->toBeNull();
    }

    $linker = Mockery::mock(CreatesConnectedAccounts::class);
    $linker->shouldReceive('create')->once()->andThrow(new RuntimeException('linking failed'));
    expect(fn () => (new CreateUserFromProvider($linker))->create('github', OAuthTwoUser::fake(['email' => 'fail@example.test'])))
        ->toThrow(RuntimeException::class, 'linking failed');
});

// =========================================================================
// 13. Module Manager Filament
// =========================================================================
it('covers ModuleManagement Filament page and plugin completely', function () {
    Auth::logout();
    expect(ModuleManagement::canAccess())->toBeFalse();

    $fixtureRoot = sys_get_temp_dir().'/module-table-'.bin2hex(random_bytes(6));
    mkdir($fixtureRoot, 0777, true);
    $originalComposer = require base_path('vendor/composer/installed.php');
    $composer = $originalComposer;

    try {
        $manifests = [];
        foreach (['alpha' => false, 'beta' => true, 'broken' => true, 'locked' => true] as $name => $enabled) {
            $dir = $fixtureRoot.'/'.$name;
            mkdir($dir);
            file_put_contents($dir.'/composer.json', json_encode(['name' => 'fixture/'.$name, 'type' => 'liberu-module', 'extra' => ['liberu' => ['name' => $name]]]));
            file_put_contents($dir.'/module.json', json_encode([
                'name' => $name, 'display_name' => ucfirst($name), 'description' => $name === 'beta' ? '' : 'Fixture '.$name,
                'version' => '1.0.0', 'category' => 'capability', 'provider' => CoverageTableFixtureProvider::class,
                'requires' => ['packages' => []], 'capabilities' => [], 'features' => ['Fixture'],
                'default_enabled' => $enabled, 'module_type' => 'feature',
            ]));
            $manifests[$name] = Manifest::fromFile($dir.'/module.json');
            $composer['versions']['fixture/'.$name] = ['pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => null, 'type' => 'liberu-module', 'install_path' => $dir, 'aliases' => [], 'dev_requirement' => false];
        }
        InstalledVersions::reload($composer);

        if (! Schema::hasTable('module_activations')) {
            Schema::create('module_activations', function ($table) {
                $table->string('module')->primary();
                $table->boolean('enabled');
                $table->timestamps();
            });
        }
        DB::table('module_activations')->truncate();

        $inventory = new ModuleInventory($manifests, [], ['broken' => ['Fixture failure']], ['locked']);
        $packages = new PackageManifest(new Filesystem(), base_path(), $fixtureRoot.'/packages.php');
        $packages->manifest = [];
        app()->instance(Modules::class, new Modules($inventory, app(ModuleActivations::class), new ModuleValidator(), $packages, [], []));

        Gate::define(ModuleManagement::ABILITY, fn (User $user) => $user->getAttribute('ops') === true);
        $opsUser = User::factory()->create();
        $opsUser->forceFill(['ops' => true]);
        $this->actingAs($opsUser);
        expect(ModuleManagement::canAccess())->toBeTrue();

        foreach ([
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            TablesServiceProvider::class,
        ] as $provider) {
            app()->register($provider);
        }
        $panel = Panel::make()->id('test-mod')->path('test-mod')->plugin(ModuleManagerFilamentPlugin::make());
        Filament::setCurrentPanel($panel);
        Filament::bootCurrentPanel();

        Livewire::test(ModuleManagement::class)
            ->assertSuccessful()
            ->assertSee('Fixture failure')
            ->assertSee('This application requires this module.')
            ->callTableAction('enable', 'alpha')
            ->callTableAction('disable', 'beta');

        expect(app(ModuleActivations::class)->all())->toHaveKey('alpha', true)->toHaveKey('beta', false);

        // Activation refusal branch
        DB::table('module_activations')->truncate();
        $path = $fixtureRoot.'/alpha/module.json';
        $data = json_decode((string) file_get_contents($path), true);
        $data['provider'] = 'MissingFixtureProvider';
        file_put_contents($path, json_encode($data));
        $manifests['alpha'] = Manifest::fromFile($path);
        $badInventory = new ModuleInventory($manifests, [], ['alpha' => ['Fixture invalid metadata']], ['locked']);
        app()->instance(Modules::class, new Modules($badInventory, app(ModuleActivations::class), new ModuleValidator(), $packages, [], []));

        Livewire::test(ModuleManagement::class)->callTableAction('enable', 'alpha');
        Notification::assertNotified('Not changed');

        $plugin = ModuleManagerFilamentPlugin::make();
        $pluginPanel = Panel::make()->id('plugin-test');
        $plugin->register($pluginPanel);
        $plugin->boot($pluginPanel);
        expect($plugin->getId())->toBe('liberu-module-manager');
    } finally {
        InstalledVersions::reload($originalComposer);
        (new Filesystem())->deleteDirectory($fixtureRoot);
    }
});

// =========================================================================
// 14. Module Manager (all 21 files in modules/module-manager/src/)
// =========================================================================
it('covers ModuleLoader, ModuleDiscovery, ModuleManagerServiceProvider, and Artisan commands', function () {
    $root = sys_get_temp_dir().'/isolated-'.bin2hex(random_bytes(5));
    mkdir($root, 0777, true);
    $original = require base_path('vendor/composer/installed.php');
    $installed = $original;
    $originalPaths = config('modules.paths');
    $originalIsolate = config('modules.isolate_failures');
    $originalRequired = config('modules.required');
    $originalDisabled = config('modules.disabled');
    $originalCachePath = config('modules.cache_path');

    try {
        coverageIsolatedModule($root, $installed, 'good');
        coverageIsolatedModule($root, $installed, 'broken-json', raw: '{ not json');
        coverageIsolatedModule($root, $installed, 'missing-keys', raw: '{"name":"missing-keys"}');
        coverageIsolatedModule($root, $installed, 'no-provider', ['provider' => 'Missing\\Provider']);
        coverageIsolatedModule($root, $installed, 'old-laravel', ['requires' => ['laravel' => '^9.0', 'packages' => []]]);
        coverageIsolatedModule($root, $installed, 'not-installed', isInstalled: false);
        coverageIsolatedModule($root, $installed, 'base', ['provider' => 'Missing\\Provider', 'capabilities' => ['base.thing']]);
        coverageIsolatedModule($root, $installed, 'middle', ['capabilities' => ['middle.thing'], 'requires' => ['packages' => [], 'capabilities' => ['base.thing' => '^1.0']]]);
        coverageIsolatedModule($root, $installed, 'top', ['requires' => ['packages' => [], 'capabilities' => ['middle.thing' => '^1.0']]]);
        coverageIsolatedModule($root, $installed, 'clash-a', ['capabilities' => ['same.thing']]);
        coverageIsolatedModule($root, $installed, 'clash-b', ['capabilities' => ['same.thing']]);

        $inventory = coverageIsolatedLoader($root)->load();
        expect($inventory->isBooted('good'))->toBeTrue()
            ->and($inventory->status('broken-json'))->toBe('invalid')
            ->and($inventory->status('middle'))->toBe('invalid')
            ->and($inventory->status('top'))->toBe('invalid')
            ->and($inventory->problems['clash-b'][0])->toContain('same.thing');

        expect(fn () => (new ModuleDiscovery())->discover([$root]))->toThrow(JsonException::class);

        $installed = $original;
        $reqRoot = sys_get_temp_dir().'/isolated-req-'.bin2hex(random_bytes(5));
        mkdir($reqRoot, 0777, true);
        coverageIsolatedModule($reqRoot, $installed, 'core');
        coverageIsolatedModule($reqRoot, $installed, 'extra');
        $reqInventory = coverageIsolatedLoader($reqRoot, required: ['core'], disabled: ['core', 'extra'])->load();
        expect($reqInventory->isBooted('core'))->toBeTrue()
            ->and($reqInventory->status('extra'))->toBe('disabled')
            ->and($reqInventory->refusedDisables)->toBe(['core']);

        coverageIsolatedModule($reqRoot, $installed, 'needed', raw: '{"name":"needed"}');
        coverageIsolatedModule($reqRoot, $installed, 'unbootable', ['provider' => 'Missing\\Provider']);
        expect(fn () => coverageIsolatedLoader($reqRoot, required: ['absent'])->load())
            ->toThrow(RequiredModuleFailed::class)
            ->and(fn () => coverageIsolatedLoader($reqRoot, required: ['needed'])->load())
            ->toThrow(RequiredModuleFailed::class)
            ->and(fn () => coverageIsolatedLoader($reqRoot, required: ['unbootable'])->load())
            ->toThrow(RequiredModuleFailed::class);

        // Cache loading and ownership key failure
        $installed = $original;
        $cleanRoot = sys_get_temp_dir().'/isolated-clean-'.bin2hex(random_bytes(5));
        mkdir($cleanRoot, 0777, true);
        coverageIsolatedModule($cleanRoot, $installed, 'good');
        $cachePath = "{$cleanRoot}/registry.cache";
        (new RegistryCache())->write((new ModuleDiscovery())->discover([$cleanRoot]), $cachePath);
        expect(array_keys(coverageIsolatedLoader($cleanRoot, cached: true, cachePath: $cachePath)->load()->booted))
            ->toContain('good');

        coverageIsolatedModule($cleanRoot, $installed, 'bad-dep', ['requires' => ['packages' => ['local/uninstalled' => '^1.0']]]);
        $badManifest = Manifest::fromFile($cleanRoot.'/bad-dep/module.json');
        $badRegistry = new ModuleRegistry(['INVALID KEY' => $badManifest]);
        $badCachePath = "{$cleanRoot}/invalid-ownership.cache";
        (new RegistryCache())->write($badRegistry, $badCachePath);
        expect(fn () => coverageIsolatedLoader($cleanRoot, cached: true, cachePath: $badCachePath)->load())
            ->toThrow(RuntimeException::class, 'Module selection could not settle:');

        // audit() & canonical checkout
        coverageIsolatedModule($cleanRoot, $installed, 'bad-metadata', ['provider' => 'AbsentProvider', 'default_enabled' => false]);
        expect(coverageIsolatedLoader($cleanRoot)->audit()['bad-metadata'][0])->toContain('not autoloadable');

        $installed = $original;
        $canonRoot = sys_get_temp_dir().'/isolated-canon-'.bin2hex(random_bytes(5));
        mkdir($canonRoot, 0777, true);
        coverageIsolatedModule($canonRoot, $installed, 'module-liberu-renamed', ['name' => 'liberu-renamed']);
        coverageIsolatedModule($canonRoot, $installed, 'liberu-renamed');
        [$canonReg, $canonProbs] = (new ModuleDiscovery())->discoverTolerant([$canonRoot]);
        expect($canonReg->get('liberu-renamed')->path)->toBe($canonRoot.'/liberu-renamed')
            ->and($canonProbs)->toBe([]);

        // ServiceProvider isolated vs fail-fast vs required-throws
        $installed = $original;
        $spRoot = sys_get_temp_dir().'/isolated-sp-'.bin2hex(random_bytes(5));
        mkdir($spRoot, 0777, true);
        coverageIsolatedModule($spRoot, $installed, 'good');
        coverageIsolatedModule($spRoot, $installed, 'explodes', ['provider' => CoverageThrowingProvider::class]);
        config(['modules.paths' => [$spRoot], 'modules.isolate_failures' => true, 'modules.required' => [], 'modules.disabled' => []]);
        $this->app->register(ModuleManagerServiceProvider::class, true);
        expect($this->app->make(ModuleInventory::class)->status('explodes'))->toBe('invalid');

        config(['modules.paths' => [$spRoot], 'modules.isolate_failures' => true, 'modules.required' => ['explodes']]);
        expect(fn () => $this->app->register(ModuleManagerServiceProvider::class, true))
            ->toThrow(RequiredModuleFailed::class, 'provider exploded');

        $installed = $original;
        $ffRoot = sys_get_temp_dir().'/isolated-ff-'.bin2hex(random_bytes(5));
        mkdir($ffRoot, 0777, true);
        coverageIsolatedModule($ffRoot, $installed, 'broken-json', raw: '{ not json');
        config(['modules.paths' => [$ffRoot], 'modules.isolate_failures' => false, 'modules.required' => []]);
        expect(fn () => $this->app->register(ModuleManagerServiceProvider::class, true))->toThrow(JsonException::class);

        // Commands with invalid module
        $installed = $original;
        $cmdRoot = sys_get_temp_dir().'/isolated-cmd-'.bin2hex(random_bytes(5));
        mkdir($cmdRoot, 0777, true);
        coverageIsolatedModule($cmdRoot, $installed, 'good');
        coverageIsolatedModule($cmdRoot, $installed, 'broken-json', raw: '{ not json');
        config(['modules.paths' => [$cmdRoot], 'modules.isolate_failures' => true, 'modules.disabled' => [], 'modules.required' => []]);
        $this->app->register(ModuleManagerServiceProvider::class, true);
        $this->app->forgetInstance(ModuleLoader::class);

        expect(Artisan::call('module:list', ['--json' => true]))->toBe(0);
        expect(Artisan::call('module:validate'))->toBe(1);
        expect(Artisan::call('module:status', ['name' => 'broken-json']))->toBe(0);
        expect(Artisan::call('module:status', ['name' => 'good']))->toBe(0);
        expect(Artisan::call('module:status', ['name' => 'nothing']))->toBe(1);
        expect(Artisan::call('module:cache'))->toBe(1);

        // Commands with valid module, features, cache, clear, strict bypass, and refused disable
        $installed = $original;
        $validRoot = sys_get_temp_dir().'/isolated-valid-'.bin2hex(random_bytes(5));
        mkdir($validRoot, 0777, true);
        coverageIsolatedModule($validRoot, $installed, 'good');
        config(['modules.paths' => [$validRoot], 'modules.isolate_failures' => false, 'modules.cache_path' => $validRoot.'/registry.cache']);
        $this->app->register(ModuleManagerServiceProvider::class, true);
        $this->app->forgetInstance(ModuleLoader::class);

        expect(Artisan::call('module:list'))->toBe(0)
            ->and(Artisan::call('module:features', ['query' => 'test']))->toBe(0)
            ->and(Artisan::call('module:features', ['query' => 'nonexistent-feature-xyz']))->toBe(0)
            ->and(Artisan::call('module:cache'))->toBe(0)
            ->and(Artisan::call('module:clear'))->toBe(0)
            ->and(Artisan::call('module:validate'))->toBe(0);

        $origPkgManifest = $this->app->make(PackageManifest::class);
        $packages = new PackageManifest(new Filesystem(), base_path(), $validRoot.'/packages.php');
        $packages->manifest = ['local/good' => ['providers' => [CoverageGoodProvider::class]]];
        $this->app->instance(PackageManifest::class, $packages);
        expect(Artisan::call('module:validate'))->toBe(0)
            ->and(Artisan::call('module:validate', ['--strict' => true]))->toBe(1);
        $this->app->instance(PackageManifest::class, $origPkgManifest);

        config(['modules.paths' => [$validRoot], 'modules.isolate_failures' => true, 'modules.required' => ['good'], 'modules.disabled' => ['good']]);
        $this->app->register(ModuleManagerServiceProvider::class, true);
        $this->app->forgetInstance(ModuleLoader::class);
        expect(Artisan::call('module:validate'))->toBe(1);

        foreach ([$root, $reqRoot, $cleanRoot, $canonRoot, $spRoot, $ffRoot, $cmdRoot, $validRoot] as $dir) {
            (new Filesystem())->deleteDirectory($dir);
        }
    } finally {
        InstalledVersions::reload($original);
        config([
            'modules.paths' => $originalPaths,
            'modules.isolate_failures' => $originalIsolate,
            'modules.required' => $originalRequired,
            'modules.disabled' => $originalDisabled,
            'modules.cache_path' => $originalCachePath,
        ]);
    }
});

it('covers ModuleManagement, ModuleActivations, ModuleValidator, and ModuleValidationGuard', function () {
    $root = sys_get_temp_dir().'/managed-'.bin2hex(random_bytes(5));
    mkdir($root, 0777, true);
    $original = require base_path('vendor/composer/installed.php');
    $composer = $original;

    try {
        if (! Schema::hasTable('module_activations')) {
            (require base_path('modules/module-manager/database/migrations/2026_11_09_000000_create_module_activations_table.php'))->up();
        }
        DB::table('module_activations')->truncate();

        coverageManagedModule($root, $composer, 'alpha', ['version' => '2.3.4', 'description' => 'Sends orders to Alpha.'], composerVersion: 'v2.3.4');
        coverageManagedModule($root, $composer, 'copied-in', composerVersion: null);
        coverageManagedModule($root, $composer, 'platform', ['module_type' => 'infrastructure', 'default_enabled' => true]);
        coverageManagedModule($root, $composer, 'undeclared', ['module_type' => null, 'default_enabled' => true]);

        $modules = coverageManagement($root)->modules();
        expect($modules)->toHaveKey('alpha')->not->toHaveKey('copied-in')
            ->and(fn () => coverageManagement($root)->disable('platform'))->toThrow(ModuleActivationRefused::class, 'infrastructure')
            ->and(fn () => coverageManagement($root)->disable('undeclared'))->toThrow(ModuleActivationRefused::class, 'infrastructure')
            ->and(fn () => coverageManagement($root)->enable('copied-in'))->toThrow(ModuleActivationRefused::class, 'not installed through Composer');

        coverageManagedModule($root, $composer, 'odd', ['module_type' => 'plugin']);
        expect(fn () => Manifest::fromFile("{$root}/odd/module.json"))->toThrow(InvalidManifest::class, 'module_type');
        (new Filesystem())->deleteDirectory("{$root}/odd");

        coverageManagement($root)->enable('alpha');
        expect(coverageManagedInventory($root)->isBooted('alpha'))->toBeTrue();
        coverageManagement($root)->disable('alpha');
        expect(coverageManagedInventory($root)->isBooted('alpha'))->toBeFalse();
        coverageManagement($root)->enable('alpha');
        expect(coverageManagedInventory($root)->isBooted('alpha'))->toBeTrue();

        expect(fn () => coverageManagement($root, required: ['alpha'])->disable('alpha'))
            ->toThrow(ModuleActivationRefused::class, 'requires this module');
        expect(fn () => coverageManagement($root, disabled: ['alpha'])->enable('alpha'))
            ->toThrow(ModuleActivationRefused::class, 'MODULES_DISABLED');
        expect(coverageManagement($root, disabled: ['alpha'])->modules()['alpha']['locked'])
            ->toContain('MODULES_DISABLED');

        coverageManagedModule($root, $composer, 'base', ['default_enabled' => true, 'capabilities' => ['base.thing']]);
        coverageManagedModule($root, $composer, 'top', ['default_enabled' => true, 'requires' => ['packages' => [], 'capabilities' => ['base.thing' => '^1.0']]]);
        expect(fn () => coverageManagement($root)->disable('base'))->toThrow(ModuleActivationRefused::class, '[top]');

        coverageManagement($root)->disable('top');
        coverageManagement($root)->disable('base');
        expect(fn () => coverageManagement($root)->enable('top'))->toThrow(ModuleActivationRefused::class, 'base.thing');

        coverageManagedModule($root, $composer, 'broken', ['default_enabled' => true, 'provider' => 'Missing\\Provider']);
        expect(coverageManagement($root)->modules()['broken']['status'])->toBe('failed');
        expect(fn () => coverageManagement($root)->enable('broken'))->toThrow(ModuleActivationRefused::class, 'not autoloadable');

        $discoveredMgmt = coverageManagement($root, discovered: [CoverageManagedProvider::class]);
        expect($discoveredMgmt->modules()['alpha']['locked'])->toContain('dont-discover')
            ->and(fn () => $discoveredMgmt->disable('alpha'))->toThrow(ModuleActivationRefused::class, 'dont-discover');

        // liberusoftware/module-liberu-* naming & dependency map ordering
        $dir = "{$root}/liberu-alpha";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/module.json", json_encode([
            'name' => 'liberu-alpha', 'display_name' => 'Alpha', 'description' => 'Test', 'version' => '1.1.2', 'category' => 'capability',
            'provider' => CoverageManagedProvider::class, 'requires' => ['packages' => []], 'capabilities' => [],
            'features' => ['Test'], 'default_enabled' => true,
        ], JSON_THROW_ON_ERROR));
        file_put_contents("{$dir}/composer.json", json_encode(['name' => 'liberusoftware/module-liberu-alpha', 'type' => 'liberu-module', 'extra' => ['liberu' => ['name' => 'liberu-alpha']]], JSON_THROW_ON_ERROR));
        $composer['versions']['liberusoftware/module-liberu-alpha'] = ['pretty_version' => '1.1.2', 'version' => '1.1.2.0', 'reference' => null, 'type' => 'liberu-module', 'install_path' => $dir, 'aliases' => [], 'dev_requirement' => false];
        InstalledVersions::reload($composer);
        expect((new ModuleValidator())->validate(new ModuleRegistry(['liberu-alpha' => Manifest::fromFile("{$dir}/module.json")]), app()->version()))->toBe([]);

        $dependencies = ['liberusoftware/first' => '^1.0', 'liberusoftware/second' => '^2.0'];
        coverageManagedModule($root, $composer, 'ordered', ['requires' => ['packages' => $dependencies]]);
        $ordPath = $root.'/ordered/composer.json';
        $ordComposer = json_decode((string) file_get_contents($ordPath), true, flags: JSON_THROW_ON_ERROR);
        $ordComposer['require'] = array_reverse($dependencies, true);
        file_put_contents($ordPath, json_encode($ordComposer, JSON_THROW_ON_ERROR));
        $ordRegistry = new ModuleRegistry(['ordered' => Manifest::fromFile($root.'/ordered/module.json')]);
        expect((new ModuleValidator())->validate($ordRegistry, app()->version()))->toBe([]);
        $ordComposer['require']['liberusoftware/first'] = '^3.0';
        file_put_contents($ordPath, json_encode($ordComposer, JSON_THROW_ON_ERROR));
        expect((new ModuleValidator())->validate($ordRegistry, app()->version()))
            ->toContain('ordered: Composer and manifest Liberu dependencies differ.');

        expect(fn () => (new ModuleValidationGuard(new ModuleValidator()))->ensureValid(new ModuleRegistry(['broken' => Manifest::fromFile($root.'/broken/module.json')]), app()->version()))
            ->toThrow(RuntimeException::class, 'Module validation failed:');
        (new ModuleValidationGuard(new ModuleValidator()))->ensureValid(new ModuleRegistry(['liberu-alpha' => Manifest::fromFile("{$dir}/module.json")]), app()->version());

        coverageManagedModule($root, $composer, 'nameless');
        $namelessPath = $root.'/nameless/composer.json';
        $namelessComp = json_decode((string) file_get_contents($namelessPath), true, flags: JSON_THROW_ON_ERROR);
        unset($namelessComp['name']);
        file_put_contents($namelessPath, json_encode($namelessComp, JSON_THROW_ON_ERROR));
        expect((new ModuleValidator())->validate(new ModuleRegistry(['nameless' => Manifest::fromFile($root.'/nameless/module.json')]), app()->version()))
            ->toContain('nameless: Composer package is not installed.');

        // Table drop fallback
        Schema::drop('module_activations');
        expect(app(ModuleActivations::class)->all())->toBe([]);
        $migration = require base_path('modules/module-manager/database/migrations/2026_11_09_000000_create_module_activations_table.php');
        $migration->up();
        $migration->down();
        $migration->up();
    } finally {
        InstalledVersions::reload($original);
        (new Filesystem())->deleteDirectory($root);
    }
});

it('covers Manifest, RegistryCache, ModuleDiscovery, ModuleRegistry, and security serialization', function () {
    $manifest = coverageMakeManifest([
        'display_name' => 'Covered', 'category' => 'presentation',
        'requires' => ['packages' => 'bad', 'capabilities' => 'bad', 'php' => '^8.5', 'laravel' => '^12.0'],
        'presentation' => ['filament' => ['admin' => [ServiceProvider::class, 42]]],
    ]);
    expect($manifest->displayName())->toBe('Covered')
        ->and($manifest->category())->toBe('presentation')
        ->and($manifest->provider())->toBe(ServiceProvider::class)
        ->and($manifest->defaultEnabled())->toBeTrue()
        ->and($manifest->features())->toBe(['Example feature'])
        ->and($manifest->requiredPackages())->toBe([])
        ->and($manifest->requiredCapabilities())->toBe([])
        ->and($manifest->phpConstraint())->toBe('^8.5')
        ->and($manifest->laravelConstraint())->toBe('^12.0')
        ->and($manifest->filamentPlugins('admin'))->toBe([ServiceProvider::class])
        ->and($manifest->filamentPlugins('app'))->toBe([])
        ->and($manifest->toArray()['path'])->toBe($manifest->path);

    foreach ([
        [['description' => null], 'missing required key'],
        [['name' => 'Bad Name'], 'invalid module name'],
        [['requires' => 'bad'], 'invalid requires, capabilities, or features'],
        [['category' => 'unknown'], 'invalid category'],
        [['default_enabled' => 1], 'default_enabled must be boolean'],
        [['capabilities' => ['Bad capability']], 'invalid capability'],
        [['features' => null], 'missing required key'],
        [['features' => 'bad'], 'invalid requires, capabilities, or features'],
        [['features' => []], 'at least one feature'],
        [['features' => [' feature']], 'invalid feature'],
        [['features' => [str_repeat('x', 121)]], 'invalid feature'],
        [['features' => ['Feature', 'feature']], 'duplicate features'],
    ] as [$mutation, $message]) {
        $data = [
            'name' => 'example', 'display_name' => 'Example', 'description' => 'Test',
            'version' => '1.0.0', 'category' => 'capability',
            'provider' => ServiceProvider::class,
            'requires' => ['packages' => [], 'capabilities' => []],
            'capabilities' => [], 'features' => ['Example feature'], 'default_enabled' => true,
        ];
        foreach ($mutation as $k => $v) {
            if ($v === null) {
                unset($data[$k]);
            } else {
                $data[$k] = $v;
            }
        }
        $dir = sys_get_temp_dir().'/liberu-invalid-'.bin2hex(random_bytes(5));
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/module.json', json_encode($data, JSON_THROW_ON_ERROR));
        expect(fn () => Manifest::fromFile($dir.'/module.json'))->toThrow(InvalidManifest::class, $message);
        (new Filesystem())->deleteDirectory($dir);
    }

    // RegistryCache round-trip, legacy private property format, unexpected class guard, and filesystem errors
    $cache = new RegistryCache();
    $path = sys_get_temp_dir().'/nested-'.bin2hex(random_bytes(5)).'/registry.cache';
    $registry = new ModuleRegistry([]);
    $cache->write($registry, $path);
    expect($cache->load([], true, $path))->toEqual($registry);
    $cache->clear($path);
    expect(is_file($path))->toBeFalse();

    file_put_contents($path, serialize('invalid'));
    expect(fn () => $cache->load([], true, $path))->toThrow(RuntimeException::class, 'cache is invalid');

    $class = ModuleRegistry::class;
    $property = "\0".$class."\0modules";
    file_put_contents($path, sprintf('O:%d:"%s":1:{s:%d:"%s";a:0:{}}', strlen($class), $class, strlen($property), $property));
    expect($cache->load([], true, $path)->all())->toBe([]);

    CoverageCachePayload::$awakened = false;
    file_put_contents($path, serialize(new ModuleRegistry(['payload' => new CoverageCachePayload()])));
    expect(fn () => $cache->load([], true, $path))->toThrow(RuntimeException::class);
    expect(CoverageCachePayload::$awakened)->toBeFalse();
    @unlink($path);

    $files = Mockery::mock(Filesystem::class);
    $files->shouldReceive('isDirectory')->andReturnTrue();
    $files->shouldReceive('put')->andReturnFalse();
    expect(fn () => (new RegistryCache($files))->write(new ModuleRegistry([]), '/cache/registry'))->toThrow(RuntimeException::class, 'Unable to write');

    $files = Mockery::mock(Filesystem::class);
    $files->shouldReceive('isFile')->andReturnTrue();
    $files->shouldReceive('delete')->andReturnFalse();
    expect(fn () => (new RegistryCache($files))->clear('/cache/registry'))->toThrow(RuntimeException::class, 'Unable to clear');

    // Discovery collisions
    foreach ([
        [function (string $root) {
            $m = coverageMakeManifest(['name' => 'one']);
            rename($m->path, $root.'/one');
        }, 'has no composer.json'],
        [function (string $root) {
            $m = coverageMakeManifest(['name' => 'one'], 'local/one');
            rename($m->path, $root.'/one');
            file_put_contents($root.'/one/composer.json', '{}');
        }, 'missing or duplicate Composer package name'],
        [function (string $root) {
            foreach (['first' => 'local/first', 'second' => 'local/second'] as $dir => $pkg) {
                $m = coverageMakeManifest(['name' => 'same'], $pkg);
                rename($m->path, $root.'/'.$dir);
            }
        }, 'Duplicate module'],
        [function (string $root) {
            foreach (['one', 'two'] as $n) {
                $m = coverageMakeManifest(['name' => $n], 'local/shared');
                rename($m->path, $root.'/'.$n);
            }
        }, 'missing or duplicate Composer package name'],
        [function (string $root) {
            foreach (['one', 'two'] as $n) {
                $m = coverageMakeManifest(['name' => $n, 'capabilities' => ['shared']], 'local/'.$n);
                rename($m->path, $root.'/'.$n);
            }
        }, 'Duplicate capability'],
    ] as [$arrange, $msg]) {
        $root = sys_get_temp_dir().'/discovery-'.bin2hex(random_bytes(5));
        mkdir($root, 0777, true);
        $arrange($root);
        expect(fn () => (new ModuleDiscovery())->discover([$root]))->toThrow(InvalidManifest::class, $msg);
        (new Filesystem())->deleteDirectory($root);
    }

    // Validator errors & version mismatch
    $invalidManifest = coverageMakeManifest([
        'name' => 'invalid', 'version' => '999.0.0', 'provider' => 'Missing\\Provider',
        'requires' => ['packages' => ['liberusoftware/missing' => '^1.0'], 'capabilities' => ['missing' => '^1.0'], 'php' => '>999', 'laravel' => '>999'],
        'presentation' => ['filament' => ['admin' => ['Missing\\Plugin']]],
    ], 'liberusoftware/missing-module');
    file_put_contents($invalidManifest->path.'/composer.json', json_encode([
        'name' => 'liberusoftware/missing-module', 'type' => 'library',
        'extra' => ['liberu' => ['name' => 'different']], 'require' => [],
    ], JSON_THROW_ON_ERROR));
    $errors = (new ModuleValidator())->validate(new ModuleRegistry(['invalid' => $invalidManifest]), '12.0.0');
    expect(implode("\n", $errors))->toContain('does not match', 'type must be', 'not installed', 'dependencies differ', 'not autoloadable', 'PHP', 'Laravel', 'only presentation', 'presentation plugin');

    $mismatchManifest = coverageMakeManifest([
        'name' => 'module-manager', 'version' => '0.0.1', 'provider' => stdClass::class,
    ], 'liberusoftware/module-manager');
    file_put_contents($mismatchManifest->path.'/composer.json', json_encode([
        'name' => 'liberusoftware/module-manager', 'type' => 'liberu-module',
        'extra' => ['liberu' => ['name' => 'module-manager']], 'require' => [],
    ], JSON_THROW_ON_ERROR));
    expect(implode("\n", (new ModuleValidator())->validate(new ModuleRegistry(['module-manager' => $mismatchManifest]), '13.0.0')))
        ->toContain('installed Composer version does not match', 'provider must extend');

    // ModuleRegistry ordering, search, resolution, and error graphs
    $foundation = coverageMakeManifest(['name' => 'foundation', 'capabilities' => ['foundation.ready'], 'features' => ['Health checks', 'Readiness']], 'local/foundation');
    $feature = coverageMakeManifest(['name' => 'feature', 'requires' => [
        'packages' => ['local/foundation' => '^1.0'], 'capabilities' => ['foundation.ready' => '^1.0'],
    ]], 'local/feature');
    $reg = new ModuleRegistry(['feature' => $feature, 'foundation' => $foundation]);
    expect($reg->has('feature'))->toBeTrue()
        ->and($reg->get('feature'))->toBe($feature)
        ->and($reg->searchFeatures())->toHaveKeys(['feature', 'foundation'])
        ->and($reg->searchFeatures('health'))->toBe(['foundation' => ['Health checks']])
        ->and($reg->searchFeatures('missing'))->toBe([])
        ->and($reg->providingFeature(' readiness '))->toBe([$foundation])
        ->and($reg->providingFeature('missing'))->toBe([])
        ->and(array_map(fn (Manifest $item) => $item->name(), $reg->resolve([])))->toBe(['foundation', 'feature'])
        ->and($reg->enabled('feature'))->toBeTrue()
        ->and($reg->enabled('feature', [], ['feature']))->toBeFalse();

    foreach ([
        [[coverageMakeManifest(['name' => 'consumer', 'requires' => ['packages' => ['missing/package' => '^1.0'], 'capabilities' => []]])], 'missing or incompatible library'],
        [[
            coverageMakeManifest(['name' => 'owner', 'version' => '2.0.0'], 'local/owner'),
            coverageMakeManifest(['name' => 'consumer', 'requires' => ['packages' => ['local/owner' => '^1.0'], 'capabilities' => []]]),
        ], 'installed manifest is 2.0.0'],
        [[
            coverageMakeManifest(['name' => 'owner', 'default_enabled' => false], 'local/owner'),
            coverageMakeManifest(['name' => 'consumer', 'requires' => ['packages' => ['local/owner' => '^1.0'], 'capabilities' => []]]),
        ], 'requires enabled package'],
        [[coverageMakeManifest(['name' => 'consumer', 'requires' => ['packages' => [], 'capabilities' => ['missing' => '^1.0']]])], 'requires missing capability'],
        [[
            coverageMakeManifest(['name' => 'one', 'requires' => ['packages' => ['local/two' => '^1.0'], 'capabilities' => []]], 'local/one'),
            coverageMakeManifest(['name' => 'two', 'requires' => ['packages' => ['local/one' => '^1.0'], 'capabilities' => []]], 'local/two'),
        ], 'Circular module dependency'],
        [[
            coverageMakeManifest(['name' => 'owner', 'default_enabled' => false, 'capabilities' => ['owned']], 'local/owner'),
            coverageMakeManifest(['name' => 'consumer', 'requires' => ['packages' => [], 'capabilities' => ['owned' => '^1.0']]], 'local/consumer'),
        ], 'requires enabled capability'],
    ] as [$mods, $msg]) {
        $mods = array_combine(array_map(fn (Manifest $m) => $m->name(), $mods), $mods);
        expect(fn () => (new ModuleRegistry($mods))->resolve([]))->toThrow(DependencyResolutionFailed::class, $msg);
    }

    $extManifest = coverageMakeManifest(['requires' => ['packages' => ['composer/semver' => '^3.0']]], 'local/external-consumer');
    expect((new ModuleRegistry(['example' => $extManifest]))->resolve([]))->toBe([$extManifest]);

    $roots = [];
    foreach (['one', 'two'] as $suffix) {
        $r = sys_get_temp_dir().'/duplicate-copy-'.$suffix.'-'.bin2hex(random_bytes(4));
        mkdir($r, 0777, true);
        $m = coverageMakeManifest(['name' => 'same'], 'local/same');
        rename($m->path, $r.'/same');
        $roots[] = $r;
    }
    expect((new ModuleDiscovery())->discover($roots)->has('same'))->toBeTrue();
    foreach ($roots as $r) {
        (new Filesystem())->deleteDirectory($r);
    }
});

// =========================================================================
// 15. Theme Support (all 10 files in modules/theme-support/src/)
// =========================================================================
it('covers ThemeCache, ThemeDiscovery, ThemeManifest, ThemeManager, commands, and helpers', function () {
    $cleanup = [];
    $originalPublic = public_path();
    $originalThemeConfig = config('theme');
    $originalRegistry = app()->bound(ModuleRegistry::class) ? app(ModuleRegistry::class) : null;

    try {
        // ThemeCache security & errors
        $secPath = tempnam(sys_get_temp_dir(), 'theme-sec-');
        $cleanup[] = $secPath;
        $tCache = new ThemeCache();
        $tCache->write([], $secPath);
        expect($tCache->load($secPath))->toBe([]);

        CoverageCachePayload::$awakened = false;
        file_put_contents($secPath, serialize(['payload' => new CoverageCachePayload()]));
        expect(fn () => $tCache->load($secPath))->toThrow(InvalidTheme::class);
        expect(CoverageCachePayload::$awakened)->toBeFalse();

        file_put_contents($secPath, serialize(['nested' => ['payload' => new stdClass()]]));
        expect(fn () => $tCache->load($secPath))->toThrow(InvalidTheme::class);

        $files = Mockery::mock(Filesystem::class);
        $files->shouldReceive('put')->andReturnFalse();
        expect(fn () => (new ThemeCache($files))->write([], '/cache/themes'))->toThrow(InvalidTheme::class, 'Unable to write');

        $files = Mockery::mock(Filesystem::class);
        $files->shouldReceive('isFile')->andReturnTrue();
        $files->shouldReceive('delete')->andReturnFalse();
        expect(fn () => (new ThemeCache($files))->clear('/cache/themes'))->toThrow(InvalidTheme::class, 'Unable to clear');

        // ThemeManifest coverage
        $m = ThemeManifest::fromFile(writeCoverageTheme(['parent' => 'base']));
        $cleanup[] = $m->path;
        expect($m->name())->toBe('covered')->and($m->displayName())->toBe('Covered')
            ->and($m->version())->toBe('1.0.0')->and($m->type())->toBe('shared')
            ->and($m->parent())->toBe('base')->and($m->assets('css'))->toBe(['theme.css'])
            ->and($m->assets('unknown'))->toBe([])->and($m->requiredCapabilities())->toBe(['one'])
            ->and($m->optionalCapabilities())->toBe(['two'])->and($m->toArray())->toHaveKeys(['label', 'path']);

        foreach ([
            [['supports' => null], '', true],
            [['name' => 'Bad Name'], 'Theme name is invalid', true],
            [['type' => 'email'], 'invalid type', true],
            [['assets' => ['css' => ['/theme.css']]], 'invalid css asset', true],
            [['assets' => ['css' => ['../theme.css']]], 'invalid css asset', true],
            [['assets' => ['css' => [42]]], 'invalid css asset', true],
            [['assets' => ['css' => ['missing.css']]], 'invalid css asset', false],
        ] as [$changes, $msg, $asset]) {
            expect(fn () => ThemeManifest::fromFile(writeCoverageTheme($changes, $asset)))->toThrow(InvalidTheme::class, $msg);
        }

        // ThemeDiscovery branches
        foreach ([
            [function (string $root) {
                $src = dirname(writeCoverageTheme());
                rename($src, $root.'/different');
                file_put_contents($root.'/different/composer.json', json_encode(['type' => 'liberu-theme', 'extra' => ['liberu' => ['name' => 'covered']]]));
            }, 'directory/name collision'],
            [function (string $root) {
                $src = dirname(writeCoverageTheme(['name' => 'covered']));
                rename($src, $root.'/covered');
            }, 'has no composer.json'],
            [function (string $root) {
                $src = dirname(writeCoverageTheme(['name' => 'covered']));
                rename($src, $root.'/covered');
                file_put_contents($root.'/covered/composer.json', '{}');
            }, 'metadata is inconsistent'],
            [function (string $root) {
                $src = dirname(writeCoverageTheme(['name' => 'covered', 'provider' => 'Missing\\ThemeProvider']));
                rename($src, $root.'/covered');
                file_put_contents($root.'/covered/composer.json', json_encode(['type' => 'liberu-theme', 'extra' => ['liberu' => ['name' => 'covered']]]));
            }, 'not autoloadable'],
        ] as [$arrange, $msg]) {
            $r = sys_get_temp_dir().'/liberu-themes-'.bin2hex(random_bytes(5));
            mkdir($r, 0777, true);
            $cleanup[] = $r;
            $arrange($r);
            expect(fn () => (new ThemeDiscovery([]))->discover($r))->toThrow(InvalidTheme::class, $msg);
        }

        expect(fn () => (new ThemeDiscovery([]))->discover(sys_get_temp_dir().'/absent-'.bin2hex(random_bytes(5))))
            ->toThrow(InvalidTheme::class, 'No themes are installed.');

        expect((new ThemeDiscovery())->discover(sys_get_temp_dir().'/absent-'.bin2hex(random_bytes(5))))
            ->toHaveKeys(['dark', 'default', 'base']);

        $dedupRoot = sys_get_temp_dir().'/liberu-themes-'.bin2hex(random_bytes(5));
        mkdir($dedupRoot.'/notes', 0777, true);
        $cleanup[] = $dedupRoot;
        rename(coverageThemePackage('covered'), $dedupRoot.'/covered');
        expect((new ThemeDiscovery([$dedupRoot.'/covered']))->discover($dedupRoot))->toHaveCount(1)->toHaveKey('covered');
        expect((new ThemeDiscovery([]))->discover($dedupRoot))->toHaveKeys(['covered']);
        expect(fn () => (new ThemeDiscovery([coverageThemePackage('covered')]))->discover($dedupRoot))
            ->toThrow(InvalidTheme::class, 'directory/name collision');

        // ComposerAvailability = false branch
        $noCompRoot = sys_get_temp_dir().'/theme-no-composer-'.bin2hex(random_bytes(5));
        mkdir($noCompRoot, 0777, true);
        $cleanup[] = $noCompRoot;
        rename(coverageThemePackage('tracked'), $noCompRoot.'/tracked');
        ComposerAvailability::$available = false;
        expect(array_keys((new ThemeDiscovery())->discover($noCompRoot)))->toBe(['tracked']);
        ComposerAvailability::$available = true;

        // Capability snapshots
        $capDir1 = sys_get_temp_dir().'/theme-cap-'.bin2hex(random_bytes(6));
        $capDir2 = sys_get_temp_dir().'/theme-cap-'.bin2hex(random_bytes(6));
        mkdir($capDir1, 0700);
        mkdir($capDir2, 0700);
        $cleanup[] = $capDir1;
        $cleanup[] = $capDir2;
        file_put_contents($capDir1.'/composer.json', json_encode(['name' => 'local/theme-foundation']));
        file_put_contents($capDir1.'/module.json', json_encode([
            'name' => 'foundation', 'display_name' => 'foundation', 'description' => 'Fixture',
            'version' => '1.0.0', 'category' => 'foundation', 'provider' => ServiceProvider::class,
            'requires' => ['packages' => [], 'capabilities' => []],
            'capabilities' => ['fixture.foundation'], 'features' => ['Fixture'], 'default_enabled' => true,
        ]));
        file_put_contents($capDir2.'/composer.json', json_encode(['name' => 'local/theme-consumer']));
        file_put_contents($capDir2.'/module.json', json_encode([
            'name' => 'consumer', 'display_name' => 'consumer', 'description' => 'Fixture',
            'version' => '1.0.0', 'category' => 'foundation', 'provider' => ServiceProvider::class,
            'requires' => ['packages' => ['local/theme-foundation' => '^1.0'], 'capabilities' => []],
            'capabilities' => ['fixture.consumer'], 'features' => ['Fixture'], 'default_enabled' => true,
        ]));
        $fMod = Manifest::fromFile($capDir1.'/module.json');
        $cMod = Manifest::fromFile($capDir2.'/module.json');
        $this->app->instance(ModuleRegistry::class, new ModuleRegistry(['foundation' => $fMod, 'consumer' => $cMod]));
        config(['modules.enabled' => [], 'modules.disabled' => []]);
        $capManager = new ThemeManager();
        $themeJsonPath = $capDir1.'/fixture-theme.json';
        file_put_contents($themeJsonPath, json_encode([
            'name' => 'fixture-theme', 'display_name' => 'Fixture', 'version' => '1.0.0',
            'provider' => ServiceProvider::class, 'type' => 'public', 'parent' => null,
            'optimized_for' => [], 'tested_with' => [], 'required_capabilities' => ['fixture.foundation'],
            'optional_capabilities' => [], 'supports' => [], 'assets' => ['css' => [], 'js' => []],
        ]));
        (new ReflectionProperty(ThemeManager::class, 'themes'))->setValue($capManager, ['fixture-theme' => ThemeManifest::fromFile($themeJsonPath)]);
        expect($capManager->themeIsCompatible('fixture-theme'))->toBeTrue()
            ->and($capManager->themeIsCompatible('fixture-theme'))->toBeTrue()
            ->and($capManager->getThemeJs('fixture-theme'))->toBeNull();

        // ThemeManager branches, helpers, Blade, ViewFinder, commands
        $mgr = new ThemeManager();
        expect($mgr->selectForSurface('unknown'))->toBe('default')
            ->and($mgr->getThemes())->not->toBeEmpty()
            ->and($mgr->providers())->not->toBeEmpty()
            ->and($mgr->getThemeAssetPath('missing'))->toBeNull()
            ->and($mgr->activeEntries())->toContain('resources/js/app.js')
            ->and($mgr->getThemeConfig('missing'))->toBe([])
            ->and($mgr->primaryColor('missing'))->toBe('amber')
            ->and($mgr->hasCustomLayout('missing'))->toBeFalse();

        $clearCacheFile = sys_get_temp_dir().'/theme-clear-'.bin2hex(random_bytes(5));
        file_put_contents($clearCacheFile, 'cached');
        config()->set('theme.cache_path', $clearCacheFile);
        $mgr->clearCache();
        expect(is_file($clearCacheFile))->toBeFalse();

        expect(fn () => $mgr->assetUrl('/unsafe'))->toThrow(InvalidTheme::class)
            ->and(fn () => $mgr->assetUrl('../unsafe'))->toThrow(InvalidTheme::class)
            ->and(fn () => $mgr->inheritanceChain('missing'))->toThrow(InvalidTheme::class);

        app()->forgetInstance(ModuleRegistry::class);
        app()->offsetUnset(ModuleRegistry::class);
        expect($mgr->themeIsCompatible('default'))->toBeTrue();

        // Missing fallback, missing deployment cache, empty cache, missing parent, cycle
        config()->set('theme.fallback', 'absent');
        expect(fn () => new ThemeManager())->toThrow(InvalidTheme::class, 'fallback theme');

        config()->set('theme.fallback', 'default');
        config()->set('theme.cache', true);
        config()->set('theme.cache_path', sys_get_temp_dir().'/absent-cache-'.bin2hex(random_bytes(5)));
        expect(fn () => new ThemeManager())->toThrow(InvalidTheme::class, 'no deployment cache');

        $emptyCache = sys_get_temp_dir().'/theme-empty-'.bin2hex(random_bytes(5));
        $cleanup[] = $emptyCache;
        (new ThemeCache())->write([], $emptyCache);
        config()->set('theme.cache_path', $emptyCache);
        expect(fn () => new ThemeManager())->toThrow(InvalidTheme::class, 'Safe fallback theme [default] is not installed.');

        $parentCache = sys_get_temp_dir().'/theme-parent-'.bin2hex(random_bytes(5));
        $cleanup[] = $parentCache;
        (new ThemeCache())->write(['default' => ThemeManifest::fromFile(writeCoverageTheme(['name' => 'default', 'parent' => 'absent']))], $parentCache);
        config()->set('theme.cache_path', $parentCache);
        expect(fn () => new ThemeManager())->toThrow(InvalidTheme::class, 'Missing parent theme [absent]');

        $cycleCache = sys_get_temp_dir().'/theme-cycle-'.bin2hex(random_bytes(5));
        $cleanup[] = $cycleCache;
        (new ThemeCache())->write(['default' => ThemeManifest::fromFile(writeCoverageTheme(['name' => 'default', 'parent' => 'default']))], $cycleCache);
        config()->set('theme.cache_path', $cycleCache);
        expect(fn () => new ThemeManager())->toThrow(InvalidTheme::class, 'inheritance cycle');

        // Contract theme manager + helpers + Vite manifest + persistence
        $name = 'contract-'.bin2hex(random_bytes(5));
        $manifestFile = writeCoverageTheme([
            'name' => $name, 'required_capabilities' => [],
            'assets' => ['css' => ['theme.css'], 'js' => ['theme.js']],
            'colors' => ['primary' => 'BLUE'],
        ]);
        $directory = dirname($manifestFile);
        file_put_contents($directory.'/theme.js', '');
        mkdir($directory.'/resources/views/layouts', 0777, true);
        mkdir($directory.'/resources/css', 0777, true);
        file_put_contents($directory.'/resources/views/layouts/contract.blade.php', 'contract');
        $tracked = base_path('themes/'.$name);
        mkdir($tracked, 0777, true);
        file_put_contents($tracked.'/theme.css', '');
        file_put_contents($tracked.'/theme.js', '');
        $public = sys_get_temp_dir().'/theme-public-'.bin2hex(random_bytes(5));
        mkdir($public.'/build', 0777, true);
        app()->usePublicPath($public);
        $hostCache = sys_get_temp_dir().'/theme-host-cache-'.bin2hex(random_bytes(5));
        (new ThemeCache())->write([$name => ThemeManifest::fromFile($manifestFile)], $hostCache);
        config(['theme.cache' => true, 'theme.cache_path' => $hostCache, 'theme.default' => $name, 'theme.fallback' => $name]);
        array_push($cleanup, $directory, $tracked, $public, $hostCache);

        $contractMgr = new ThemeManager();
        app()->instance(ThemeManager::class, $contractMgr);
        include base_path('modules/theme-support/src/Support/theme_helpers.php');

        expect(theme())->toBe($contractMgr)
            ->and(active_theme())->toBe($name)
            ->and(theme_asset('logo.svg'))->toBe(asset('themes/'.$name.'/logo.svg'))
            ->and(theme_asset('logo.svg', $name))->toBe(asset('themes/'.$name.'/logo.svg'))
            ->and(theme_path())->toBe($directory)
            ->and(theme_views_path())->toBe($directory.'/resources/views')
            ->and(theme_layout('contract'))->toBe('layouts.contract')
            ->and($contractMgr->getThemeAssetPath('css'))->toBe($directory.'/resources/css')
            ->and($contractMgr->getThemePath('missing'))->toEndWith('/missing')
            ->and($contractMgr->getThemeCss())->toBe('themes/'.$name.'/theme.css')
            ->and($contractMgr->getThemeJs())->toBe('themes/'.$name.'/theme.js')
            ->and($contractMgr->hasCustomLayout('contract'))->toBeTrue()
            ->and($contractMgr->primaryColor())->toBe('blue')
            ->and($contractMgr->viteHasAsset('missing'))->toBeFalse();

        file_put_contents($public.'/build/manifest.json', json_encode(['themes/'.$name.'/theme.css' => ['file' => 'theme.css']]));
        expect($contractMgr->viteHasAsset('themes/'.$name.'/theme.css'))->toBeTrue()
            ->and($contractMgr->activeCssEntry())->toBe('themes/'.$name.'/theme.css')
            ->and($contractMgr->activeEntries())->toBe([
                'resources/css/app.css', 'themes/'.$name.'/theme.css',
                'themes/'.$name.'/theme.js', 'resources/js/app.js',
            ]);
        file_put_contents($public.'/build/manifest.json', '[]');
        expect($contractMgr->viteHasAsset('missing'))->toBeFalse()
            ->and($contractMgr->activeCssEntry())->toBe('resources/css/app.css');
        unlink($public.'/build/manifest.json');
        expect(array_count_values($contractMgr->activeEntries())['resources/css/app.css'])->toBe(1);

        config(['theme.surfaces.invalid' => []]);
        expect($contractMgr->selectForSurface('invalid'))->toBe($name);

        $actor = User::factory()->create();
        Auth::setUser($actor);
        $contractMgr->persistTheme($name);
        set_theme('unavailable');
        expect(active_theme())->toBe($name)
            ->and(session('theme_preference'))->toBe($name)
            ->and($actor->fresh()->theme_preference)->toBe($name);

        // Capability-gated theme when no ModuleRegistry is bound
        $meta = json_decode((string) file_get_contents($manifestFile), true, flags: JSON_THROW_ON_ERROR);
        $meta['required_capabilities'] = ['fixture.unavailable'];
        file_put_contents($manifestFile, json_encode($meta, JSON_THROW_ON_ERROR));
        (new ThemeCache())->write([$name => ThemeManifest::fromFile($manifestFile)], $hostCache);
        app()->forgetInstance(ModuleRegistry::class);
        app()->offsetUnset(ModuleRegistry::class);
        expect((new ThemeManager())->themeIsCompatible($name))->toBeFalse();

        // Reset to default theme config for Blade/View/Commands tests
        config(['theme' => $originalThemeConfig]);
        $defaultMgr = new ThemeManager();
        app()->instance(ThemeManager::class, $defaultMgr);

        $cmdCache = sys_get_temp_dir().'/theme-command-'.bin2hex(random_bytes(5));
        $cleanup[] = $cmdCache;
        config(['theme.cache_path' => $cmdCache]);
        expect(Artisan::call('theme:validate'))->toBe(0)
            ->and(Artisan::call('theme:cache'))->toBe(0)
            ->and(is_file($cmdCache))->toBeTrue()
            ->and(Artisan::call('theme:clear'))->toBe(0)
            ->and(is_file($cmdCache))->toBeFalse();

        $origFinder = View::getFinder();
        $customFinder = $this->createStub(ViewFinderInterface::class);
        View::setFinder($customFinder);
        try {
            app(ThemeManager::class)->registerThemePaths();
            expect(View::getFinder())->toBe($customFinder);
        } finally {
            View::setFinder($origFinder);
        }

        // Blade view composer: missing attribute, actor preference, session override, site settings, aliases
        $projected = new class() extends FoundationUser {};
        $projected->forceFill(['id' => 1, 'name' => 'Projected Actor']);
        $projected->exists = true;
        Auth::setUser($projected);
        $prevStrict = Model::preventsAccessingMissingAttributes();
        Model::preventAccessingMissingAttributes();
        try {
            expect(Blade::render('{{ $activeTheme }}'))->toBe('default');
        } finally {
            Model::preventAccessingMissingAttributes($prevStrict);
        }

        $prefActor = new class() extends FoundationUser {};
        $prefActor->forceFill(['id' => 1, 'theme_preference' => 'dark']);
        Auth::setUser($prefActor);
        expect(Blade::render('{{ $activeTheme }}'))->toBe('dark');
        $prefActor->forceFill(['theme_preference' => 'missing']);
        session(['theme_preference' => 'default']);
        expect(Blade::render('{{ $activeTheme }}'))->toBe('default');
        session()->forget('theme_preference');

        $settings = $this->createStub(SiteSettings::class);
        $settings->active_theme = 'dark';
        app()->instance(SiteSettings::class, $settings);
        expect(Blade::render('{{ $activeTheme }}'))->toBe('dark');
        $settings->active_theme = 'missing';
        expect(Blade::render('{{ $activeTheme }}'))->toBe('default');

        config(['theme.preference_aliases' => ['legacy-default' => 'dark']]);
        $prefActor->forceFill(['theme_preference' => 'legacy-default']);
        expect(Blade::render('{{ $activeTheme }}'))->toBe('dark');
        $prefActor->forceFill(['theme_preference' => null]);
        session(['theme_preference' => 'legacy-default']);
        expect(Blade::render('{{ $activeTheme }}'))->toBe('dark');

        foreach (['unavailable', [], ''] as $badTarget) {
            config(['theme.preference_aliases' => ['legacy-default' => $badTarget]]);
            $prefActor->forceFill(['theme_preference' => 'legacy-default']);
            session(['theme_preference' => 'dark']);
            expect(Blade::render('{{ $activeTheme }}'))->toBe('dark');
        }

        config(['theme.preference_aliases' => 'invalid']);
        $prefActor->forceFill(['theme_preference' => '']);
        session(['theme_preference' => '']);
        expect(Blade::render('{{ $activeTheme }}'))->toBe('default');

        // Module default view resolution chain: child theme -> parent theme -> module default -> application fallback
        $parentManifest = writeCoverageTheme(['name' => 'parent-theme', 'required_capabilities' => []]);
        $childManifest = writeCoverageTheme(['name' => 'child-theme', 'parent' => 'parent-theme', 'required_capabilities' => []]);
        $emptyManifest = writeCoverageTheme(['name' => 'empty-theme', 'required_capabilities' => []]);
        $parentDir = dirname($parentManifest);
        $childDir = dirname($childManifest);
        $emptyDir = dirname($emptyManifest);
        $moduleViews = sys_get_temp_dir().'/module-views-'.bin2hex(random_bytes(5));
        $appViews = sys_get_temp_dir().'/app-views-'.bin2hex(random_bytes(5));
        mkdir($parentDir.'/resources/views/modules/catalog', 0777, true);
        mkdir($childDir.'/resources/views/modules/catalog', 0777, true);
        mkdir($emptyDir.'/resources/views', 0777, true);
        mkdir($moduleViews, 0777, true);
        mkdir($appViews, 0777, true);
        file_put_contents($moduleViews.'/card.blade.php', 'module-default-card');
        file_put_contents($moduleViews.'/summary.blade.php', 'module-default-summary');
        file_put_contents($parentDir.'/resources/views/modules/catalog/card.blade.php', 'parent-override-card');
        file_put_contents($parentDir.'/resources/views/modules/catalog/summary.blade.php', 'parent-override-summary');
        file_put_contents($childDir.'/resources/views/modules/catalog/card.blade.php', 'child-override-card');
        file_put_contents($appViews.'/fallback-only.blade.php', 'application-fallback');

        $chainCache = sys_get_temp_dir().'/theme-chain-cache-'.bin2hex(random_bytes(5));
        (new ThemeCache())->write([
            'parent-theme' => ThemeManifest::fromFile($parentManifest),
            'child-theme' => ThemeManifest::fromFile($childManifest),
            'empty-theme' => ThemeManifest::fromFile($emptyManifest),
        ], $chainCache);
        array_push($cleanup, $parentDir, $childDir, $emptyDir, $moduleViews, $appViews, $chainCache);

        View::addLocation($appViews);
        View::addNamespace('catalog', $moduleViews);
        config([
            'theme.cache' => true,
            'theme.cache_path' => $chainCache,
            'theme.default' => 'empty-theme',
            'theme.fallback' => 'empty-theme',
            'theme.tenants.acme.storefront' => 'child-theme',
            'theme.tenants.globex.storefront' => 'parent-theme',
        ]);
        $chainMgr = new ThemeManager();
        app()->instance(ThemeManager::class, $chainMgr);

        $chainMgr->setTheme('empty-theme');
        expect(View::make('catalog::card')->render())->toBe('module-default-card')
            ->and($chainMgr->describeViewSource('catalog::card'))->toBe([
                'view' => 'catalog::card',
                'source' => 'module',
                'theme' => null,
                'namespace' => 'catalog',
            ]);

        $chainMgr->selectForSurface('storefront', tenant: 'acme');
        expect(View::make('catalog::card')->render())->toBe('child-override-card')
            ->and($chainMgr->describeViewSource('catalog::card'))->toBe([
                'view' => 'catalog::card',
                'source' => 'theme',
                'theme' => 'child-theme',
                'namespace' => 'catalog',
            ])
            ->and(View::make('catalog::summary')->render())->toBe('parent-override-summary')
            ->and($chainMgr->describeViewSource('catalog::summary'))->toBe([
                'view' => 'catalog::summary',
                'source' => 'theme',
                'theme' => 'parent-theme',
                'namespace' => 'catalog',
            ]);

        $chainMgr->selectForSurface('storefront', tenant: 'globex');
        expect(View::make('catalog::card')->render())->toBe('parent-override-card')
            ->and($chainMgr->describeViewSource('catalog::card')['theme'])->toBe('parent-theme');

        expect(View::make('fallback-only')->render())->toBe('application-fallback')
            ->and($chainMgr->describeViewSource('fallback-only'))->toBe([
                'view' => 'fallback-only',
                'source' => 'application',
                'theme' => null,
                'namespace' => null,
            ]);
    } finally {
        ComposerAvailability::$available = true;
        app()->usePublicPath($originalPublic);
        config(['theme' => $originalThemeConfig]);
        app()->instance(ThemeManager::class, $defaultMgr ?? new ThemeManager());
        if ($originalRegistry !== null) {
            app()->instance(ModuleRegistry::class, $originalRegistry);
        }
        foreach ($cleanup as $path) {
            if (is_dir($path)) {
                (new Filesystem())->deleteDirectory($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
    }
});
