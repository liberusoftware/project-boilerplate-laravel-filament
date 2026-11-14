<?php

use App\Models\User;
use Filament\Events\TenantSet;
use Filament\Facades\Filament;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\Tenancy\Concerns\IsTenantModel;
use Liberu\Foundation\Tenancy\Http\Middleware\SetPermissionsTeamContext;
use Liberu\Foundation\Tenancy\Http\Middleware\SetTenantContext;
use Liberu\Foundation\Tenancy\Jobs\Concerns\TenantAware;
use Liberu\Foundation\Tenancy\Jobs\Middleware\RestoreTenantContext;
use Liberu\Foundation\Tenancy\Listeners\SwitchTeam;
use Liberu\Foundation\Tenancy\TenantContext;
use Liberu\Foundation\Tenancy\TenantState;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    TenantContext::clear();
    Filament::setTenant(null);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

afterEach(function (): void {
    TenantContext::clear();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('resolves explicit and Filament tenant context without retaining state across scopes', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;
    Filament::setTenant($team);
    expect(TenantContext::currentId())->toBe($team->id);
    TenantContext::set(123456);
    $previous = app(TenantState::class);
    expect(TenantContext::currentId())->toBe(123456);
    app()->forgetScopedInstances();
    expect(app(TenantState::class))->not->toBe($previous)
        ->and(app(TenantState::class)->teamId)->toBeNull()
        ->and($previous->teamId)->toBe(123456);
    Filament::setTenant(null);
    expect(TenantContext::currentId())->toBeNull();
});

it('treats an unavailable Filament context as an absent tenant', function (): void {
    Filament::shouldReceive('getTenant')->once()->andThrow(new RuntimeException('No panel context'));

    expect(TenantContext::currentId())->toBeNull();
});

it('resolves only model actors with an available current team relation', function (): void {
    expect(TenantContext::forUser(null))->toBeNull()
        ->and(TenantContext::forUser(new GenericUser(['id' => 1])))->toBeNull()
        ->and(TenantContext::forUser(new Illuminate\Foundation\Auth\User()))->toBeNull();
    $user = User::factory()->withPersonalTeam()->create();
    expect(TenantContext::forUser($user))->toBe($user->currentTeam->id);
    $user->setRelation('currentTeam', null);
    expect(TenantContext::forUser($user))->toBeNull();
});

it('sets the request tenant from Sanctum and clears it at termination', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user, 'sanctum');
    $request = Request::create('/tenant-contract');
    $middleware = new SetTenantContext();
    $expected = new Response('handled', 202);
    $response = $middleware->handle($request, function (Request $incoming) use ($request, $user, $expected): Response {
        expect($incoming)->toBe($request)->and(TenantContext::currentId())->toBe($user->currentTeam->id);

        return $expected;
    });
    expect($response)->toBe($expected);
    $middleware->terminate($request, $response);
    expect(app(TenantState::class)->teamId)->toBeNull();
});

it('sets permission teams for authenticated and request-resolved users and clears them at termination', function (bool $sanctum): void {
    $user = User::factory()->withPersonalTeam()->create();
    $request = Request::create('/permission-team-contract');
    if ($sanctum) {
        $this->actingAs($user, 'sanctum');
    } else {
        $request->setUserResolver(fn () => $user);
    }
    $middleware = new SetPermissionsTeamContext();
    $response = $middleware->handle($request, function () use ($user): Response {
        expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($user->currentTeam->id);

        return new Response('permissions');
    });
    $middleware->terminate($request, $response);
    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
})->with(['Sanctum actor' => true, 'request actor' => false]);

it('clears a previous permission team when the next request has no actor', function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(123);
    (new SetPermissionsTeamContext())->handle(Request::create('/guest'), function (): Response {
        expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();

        return new Response('guest');
    });
});

it('captures the submitting tenant and restores it only for the lifetime of a queued job', function (bool $fails): void {
    TenantContext::set(123);
    $job = new class()
    {
        use TenantAware;

        public function __construct()
        {
            $this->captureTenant();
        }
    };
    TenantContext::set(456);
    expect($job->tenantId)->toBe(123)->and($job->middleware()[0])->toBeInstanceOf(RestoreTenantContext::class);
    $invoke = fn () => $job->middleware()[0]->handle($job, function (object $received) use ($job, $fails): string {
        expect($received)->toBe($job)->and(TenantContext::currentId())->toBe(123);
        if ($fails) {
            throw new RuntimeException('job failure');
        }

        return 'completed';
    });
    if ($fails) {
        expect($invoke)->toThrow(RuntimeException::class, 'job failure');
    } else {
        expect($invoke())->toBe('completed');
    }
    expect(TenantContext::currentId())->toBeNull();
})->with(['successful job' => false, 'failed job' => true]);

it('does not inherit a previous worker tenant for a job without tenant metadata', function (): void {
    TenantContext::set(123);
    $job = new stdClass();
    $result = (new RestoreTenantContext())->handle($job, function (): string {
        expect(TenantContext::currentId())->toBeNull();

        return 'global job';
    });

    expect($result)->toBe('global job')->and(TenantContext::currentId())->toBeNull();
});

it('updates permission context only for Filament tenant events', function (): void {
    $listener = new SwitchTeam();
    app(PermissionRegistrar::class)->setPermissionsTeamId(123);
    $listener->handle(new stdClass());
    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe(123);
    $user = User::factory()->withPersonalTeam()->create();
    $listener->handle(new TenantSet($user->currentTeam, $user));
    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($user->currentTeam->id);
});

it('scopes model reads and defaults ownership without overwriting explicit team assignments', function (): void {
    Schema::create('tenant_contract_records', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('team_id')->nullable();
        $table->string('name');
    });
    $user = User::factory()->withPersonalTeam()->create();
    $other = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $otherTeam = $other->currentTeam;
    TenantContext::set($team->id);
    $own = TenantContractRecord::create(['name' => 'own record']);
    $foreign = TenantContractRecord::create(['name' => 'foreign record', 'team_id' => $otherTeam->id]);
    expect($own->team_id)->toBe($team->id)->and($foreign->team_id)->toBe($otherTeam->id)
        ->and(TenantContractRecord::pluck('name')->all())->toBe(['own record'])
        ->and($own->team->id)->toBe($team->id)
        ->and($own->belongsToTeam($team->id))->toBeTrue()
        ->and($own->belongsToTeam($otherTeam->id))->toBeFalse()
        ->and($own->belongsToTeam(null))->toBeTrue();
    TenantContext::clear();
    expect(TenantContractRecord::byTeam($otherTeam->id)->pluck('name')->all())->toBe(['foreign record'])
        ->and(TenantContractRecord::byTeam(null)->count())->toBe(2);
    $global = TenantContractRecord::create(['name' => 'global record']);
    expect($global->team_id)->toBeNull();
});

final class TenantContractRecord extends Model
{
    use IsTenantModel;

    protected $table = 'tenant_contract_records';

    protected $guarded = [];

    public $timestamps = false;
}
