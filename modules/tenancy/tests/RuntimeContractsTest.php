<?php

declare(strict_types=1);

namespace Liberu\Foundation\Tenancy\Tests;

use Filament\Events\TenantSet;
use Filament\FilamentManager;
use Illuminate\Auth\GenericUser;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Laravel\Jetstream\Jetstream;
use Liberu\Foundation\Tenancy\Concerns\IsTenantModel;
use Liberu\Foundation\Tenancy\Http\Middleware\SetPermissionsTeamContext;
use Liberu\Foundation\Tenancy\Http\Middleware\SetTenantContext;
use Liberu\Foundation\Tenancy\Jobs\Concerns\TenantAware;
use Liberu\Foundation\Tenancy\Jobs\Middleware\RestoreTenantContext;
use Liberu\Foundation\Tenancy\Listeners\SwitchTeam;
use Liberu\Foundation\Tenancy\TenancyServiceProvider;
use Liberu\Foundation\Tenancy\TenantContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class RuntimeContractsTest extends TestCase
{
    private Application $app;

    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    private mixed $previousResolver;

    private mixed $previousDispatcher;

    private string $previousTeamModel;

    private Capsule $database;

    private FilamentManager $panel;

    private ?RuntimeActor $actor = null;

    private int|string|null $permissionTeam = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->previousTeamModel = Jetstream::teamModel();
        $this->app = new Application(sys_get_temp_dir());
        $this->app->instance('config', new Repository());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        (new TenancyServiceProvider($this->app))->register();
        $this->panel = $this->createStub(FilamentManager::class);
        $this->app->instance('filament', $this->panel);
        $guard = $this->createStub(Guard::class);
        $guard->method('user')->willReturnCallback(fn () => $this->actor);
        $auth = $this->createStub(Factory::class);
        $auth->method('guard')->willReturnCallback(function (?string $name = null) use ($guard): Guard {
            $this->assertSame('sanctum', $name);

            return $guard;
        });
        $this->app->instance(Factory::class, $auth);
        $permissions = $this->createStub(PermissionRegistrar::class);
        $permissions->method('setPermissionsTeamId')->willReturnCallback(function ($id): void {
            $this->permissionTeam = $id;
        });
        $permissions->method('getPermissionsTeamId')->willReturnCallback(fn () => $this->permissionTeam);
        $this->app->instance(PermissionRegistrar::class, $permissions);
        $this->database = new Capsule($this->app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setEventDispatcher(new Dispatcher($this->app));
        Model::clearBootedModels();
        $this->database->bootEloquent();
        Jetstream::useTeamModel(RuntimeTeam::class);
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('runtime_teams', function (Blueprint $table): void {
            $table->id();
        });
        $schema->create('runtime_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        Model::clearBootedModels();
        if ($this->previousResolver !== null) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        if ($this->previousDispatcher !== null) {
            Model::setEventDispatcher($this->previousDispatcher);
        } else {
            Model::unsetEventDispatcher();
        }
        Jetstream::useTeamModel($this->previousTeamModel);
        $this->app->flush();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_panel_fallback_and_explicit_context_have_the_expected_precedence(): void
    {
        $team = RuntimeTeam::create();
        $this->panel->method('getTenant')->willReturn($team);
        $this->assertSame($team->id, TenantContext::currentId());
        TenantContext::set(99);
        $this->assertSame(99, TenantContext::currentId());
        TenantContext::clear();
        $this->assertSame($team->id, TenantContext::currentId());
    }

    public function test_panel_failure_and_unsupported_actors_have_no_tenant(): void
    {
        $this->panel->method('getTenant')->willThrowException(new RuntimeException('No panel'));
        $this->assertNull(TenantContext::currentId());
        $this->assertNull(TenantContext::forUser(new GenericUser(['id' => 1])));
        $actor = new RuntimeActor();
        $actor->setRelation('currentTeam', null);
        $this->assertNull(TenantContext::forUser($actor));
    }

    public function test_request_context_is_visible_only_until_termination(): void
    {
        $this->actor = new RuntimeActor();
        $team = RuntimeTeam::create();
        $this->actor->setRelation('currentTeam', $team);
        $request = Request::create('/team');
        $middleware = new SetTenantContext();
        $expected = new Response('handled', 202);
        $response = $middleware->handle($request, function (Request $incoming) use ($request, $team, $expected): Response {
            $this->assertSame($request, $incoming);
            $this->assertSame($team->id, TenantContext::currentId());

            return $expected;
        });
        $this->assertSame($expected, $response);
        $middleware->terminate($request, $response);
        $this->assertNull(TenantContext::currentId());
    }

    public function test_permission_context_supports_both_guard_and_request_actors_and_cleans_up(): void
    {
        $actor = new RuntimeActor();
        $team = RuntimeTeam::create();
        $actor->setRelation('currentTeam', $team);
        $middleware = new SetPermissionsTeamContext();
        foreach ([false, true] as $useGuard) {
            $this->actor = $useGuard ? $actor : null;
            $request = Request::create('/permissions');
            $request->setUserResolver(fn () => $actor);
            $response = $middleware->handle($request, function () use ($team): Response {
                $this->assertSame($team->id, getPermissionsTeamId());

                return new Response('handled');
            });
            $middleware->terminate($request, $response);
            $this->assertNull(getPermissionsTeamId());
        }
        $this->actor = null;
        $this->permissionTeam = 99;
        $middleware->handle(Request::create('/guest'), function (): Response {
            $this->assertNull(getPermissionsTeamId());

            return new Response('guest');
        });
    }

    public function test_tenant_aware_jobs_capture_context_and_unscoped_jobs_do_not_inherit_it(): void
    {
        TenantContext::set(42);
        $job = new class()
        {
            use TenantAware;

            public function __construct()
            {
                $this->captureTenant();
            }
        };
        TenantContext::set(99);
        $this->assertSame(42, $job->tenantId);
        $this->assertInstanceOf(RestoreTenantContext::class, $job->middleware()[0]);
        $this->assertSame(42, $job->middleware()[0]->handle($job, fn () => TenantContext::currentId()));
        $this->assertNull(TenantContext::currentId());
        TenantContext::set(99);
        $this->assertNull(new RestoreTenantContext()->handle(new stdClass(), fn () => TenantContext::currentId()));
        $this->assertNull(TenantContext::currentId());
    }

    public function test_only_tenant_events_change_the_permission_team(): void
    {
        $listener = new SwitchTeam();
        $this->permissionTeam = 99;
        $listener->handle(new stdClass());
        $this->assertSame(99, getPermissionsTeamId());
        $team = RuntimeTeam::create();
        $listener->handle(new TenantSet($team, new RuntimeActor()));
        $this->assertSame($team->id, getPermissionsTeamId());
    }

    public function test_model_scopes_default_ownership_and_explicit_team_relationships(): void
    {
        $ownTeam = RuntimeTeam::create();
        $otherTeam = RuntimeTeam::create();
        TenantContext::set($ownTeam->id);
        $own = RuntimeRecord::create(['name' => 'own']);
        $other = RuntimeRecord::create(['name' => 'other', 'team_id' => $otherTeam->id]);
        $this->assertSame($ownTeam->id, $own->team_id);
        $this->assertSame($otherTeam->id, $other->team_id);
        $this->assertSame(['own'], RuntimeRecord::pluck('name')->all());
        $this->assertSame($ownTeam->id, $own->team->id);
        $this->assertTrue($own->belongsToTeam($ownTeam->id));
        $this->assertFalse($own->belongsToTeam($otherTeam->id));
        $this->assertTrue($own->belongsToTeam(null));
        TenantContext::clear();
        $this->assertSame(['other'], RuntimeRecord::byTeam($otherTeam->id)->pluck('name')->all());
        $this->assertSame(2, RuntimeRecord::byTeam(null)->count());
        $this->assertNull(RuntimeRecord::create(['name' => 'global'])->team_id);
    }
}

class RuntimeActor extends User
{
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(RuntimeTeam::class, 'current_team_id');
    }
}

class RuntimeTeam extends Model
{
    protected $table = 'runtime_teams';

    protected $guarded = [];

    public $timestamps = false;
}

class RuntimeRecord extends Model
{
    use IsTenantModel;

    protected $table = 'runtime_records';

    protected $guarded = [];

    public $timestamps = false;
}
