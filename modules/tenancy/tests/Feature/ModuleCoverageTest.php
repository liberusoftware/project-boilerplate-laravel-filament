<?php

declare(strict_types=1);

use Filament\Events\TenantSet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\Tenancy\Concerns\IsTenantModel;
use Liberu\Foundation\Tenancy\Http\Middleware\SetPermissionsTeamContext;
use Liberu\Foundation\Tenancy\Http\Middleware\SetTenantContext;
use Liberu\Foundation\Tenancy\Jobs\Concerns\TenantAware;
use Liberu\Foundation\Tenancy\Jobs\Middleware\RestoreTenantContext;
use Liberu\Foundation\Tenancy\Listeners\SwitchTeam;
use Liberu\Foundation\Tenancy\TenancyServiceProvider;
use Liberu\Foundation\Tenancy\TenantContext;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

it('covers TenancyServiceProvider, TenantContext, middleware, listeners, jobs, and IsTenantModel', function (): void {
    $provider = $this->app->register(TenancyServiceProvider::class, force: true);
    $provider->register();

    // TenantContext set / currentId / clear
    TenantContext::clear();
    expect(TenantContext::currentId())->toBeNull();

    TenantContext::set(77);
    expect(TenantContext::currentId())->toBe(77);

    TenantContext::clear();
    expect(TenantContext::currentId())->toBeNull();

    // TenantContext::forUser with null, model without currentTeam, and model with currentTeam
    expect(TenantContext::forUser(null))->toBeNull();

    $plainUser = new class() extends Authenticatable {};
    expect(TenantContext::forUser($plainUser))->toBeNull();

    $teamModel = new class() extends Model
    {
        protected $guarded = [];
    };
    $teamModel->setAttribute('id', 55);

    $teamUser = new class() extends Authenticatable
    {
        public function currentTeam(): mixed
        {
            return null;
        }
    };
    $teamUser->setRelation('currentTeam', $teamModel);
    expect(TenantContext::forUser($teamUser))->toBe(55);

    // SetTenantContext middleware handle + terminate
    $tenantMiddleware = new SetTenantContext();
    $request = Request::create('/api/items', 'GET');
    $response = $tenantMiddleware->handle($request, static fn (Request $r): Response => new Response('ok'));
    expect($response->getContent())->toBe('ok');
    $tenantMiddleware->terminate($request, $response);
    expect(TenantContext::currentId())->toBeNull();

    // SetPermissionsTeamContext middleware handle + terminate
    $permMiddleware = new SetPermissionsTeamContext();
    $requestWithUser = Request::create('/api/items', 'GET');
    $requestWithUser->setUserResolver(static fn () => $teamUser);
    $permResponse = $permMiddleware->handle($requestWithUser, static fn (Request $r): Response => new Response('ok'));
    expect($permResponse->getContent())->toBe('ok')
        ->and(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe(55);
    $permMiddleware->terminate($requestWithUser, $permResponse);
    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();

    // SwitchTeam listener handles TenantSet and ignores unrelated events
    $listener = new SwitchTeam();
    $listener->handle(new stdClass());
    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();

    $listener->handle(new TenantSet($teamModel, $teamUser));
    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe(55);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // TenantAware + RestoreTenantContext
    TenantContext::set(88);
    $job = new class()
    {
        use TenantAware;

        public function __construct()
        {
            $this->captureTenant();
        }
    };
    expect($job->tenantId)->toBe(88)
        ->and($job->middleware())->toHaveCount(1);

    TenantContext::clear();
    $seenDuringJob = null;
    (new RestoreTenantContext())->handle($job, function () use (&$seenDuringJob): string {
        $seenDuringJob = TenantContext::currentId();

        return 'done';
    });
    expect($seenDuringJob)->toBe(88)
        ->and(TenantContext::currentId())->toBeNull();

    // IsTenantModel global scope, creating hook, scopeByTeam, belongsToTeam
    Schema::create('tenancy_test_items', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('team_id')->nullable();
        $table->string('name');
        $table->timestamps();
    });

    try {
        $itemClass = new class() extends Model
        {
            use IsTenantModel;

            protected $table = 'tenancy_test_items';

            protected $guarded = [];
        };

        TenantContext::set(10);
        $itemA = $itemClass::create(['name' => 'Alpha']);
        expect((int) $itemA->team_id)->toBe(10);

        TenantContext::set(20);
        $itemB = $itemClass::create(['name' => 'Beta']);
        expect((int) $itemB->team_id)->toBe(20);

        // Global scope isolates team 20
        expect($itemClass::query()->pluck('name')->all())->toBe(['Beta']);

        // Clear tenant context and test scopeByTeam + belongsToTeam
        TenantContext::clear();
        expect($itemClass::query()->byTeam(10)->pluck('name')->all())->toBe(['Alpha'])
            ->and($itemClass::query()->byTeam(null)->count())->toBe(2)
            ->and($itemA->belongsToTeam(10))->toBeTrue()
            ->and($itemA->belongsToTeam(20))->toBeFalse()
            ->and($itemA->belongsToTeam(null))->toBeTrue();
    } finally {
        TenantContext::clear();
        Schema::dropIfExists('tenancy_test_items');
    }
});
