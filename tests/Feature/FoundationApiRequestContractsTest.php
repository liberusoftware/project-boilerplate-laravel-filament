<?php

use App\Models\User;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as HostRoute;
use Liberu\Foundation\ApiAccess\ApiAccessServiceProvider;
use Liberu\Foundation\ApiAccess\Http\Middleware\ApiContract;
use Liberu\Foundation\ApiAccess\Http\Middleware\Idempotency;
use Liberu\Foundation\ApiAccess\Http\Middleware\ReplayIdempotentRequest;
use Liberu\Foundation\ApiAccess\Support\IdempotencyStore;
use Liberu\Foundation\ApiAccess\Support\TokenPolicy;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('replays native authenticated HTTP writes while isolating users and rejecting changed payloads', function () {
    $writes = 0;
    HostRoute::middleware(['api', ApiContract::class])->post('/foundation-api-contract', function () use (&$writes) {
        return response()->json(['write' => ++$writes], 201);
    });
    $actor = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($actor);
    $headers = ['Idempotency-Key' => 'native-http-write'];

    $this->postJson('/foundation-api-contract', ['amount' => 100], $headers)
        ->assertCreated()->assertExactJson(['write' => 1]);
    $this->postJson('/foundation-api-contract', ['amount' => 100], $headers)
        ->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertExactJson(['write' => 1]);
    $this->postJson('/foundation-api-contract', ['amount' => 200], $headers)
        ->assertConflict()->assertJsonPath('type', 'https://liberu.software/problems/idempotency-conflict');
    $this->actingAs($other)->postJson('/foundation-api-contract', ['amount' => 100], $headers)
        ->assertCreated()->assertExactJson(['write' => 2]);

    expect($writes)->toBe(2);
});

beforeEach(function () {
    $this->foundationApiDatabase = DB::getFacadeRoot();
});

afterEach(function () {
    DB::swap($this->foundationApiDatabase);
});

function foundationApiContractRequest(string $method = 'POST', string $body = 'request', array $headers = [], ?Model $model = null): Request
{
    $request = Request::create('/records/1', $method, [], [], [], [], $body);
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    if ($model !== null) {
        $route = new Route($method, 'records/{record}', static fn () => null);
        $route->bind($request);
        $route->setParameter('record', $model);
        $request->setRouteResolver(static fn () => $route);
    }

    return $request;
}

function foundationApiContractModel(?string $updatedAt = '2026-01-01 12:00:00'): Model
{
    $model = new class() extends Model
    {
        protected $casts = ['updated_at' => 'datetime'];
    };

    return $model->forceFill(['id' => 1, 'updated_at' => $updatedAt]);
}

it('adds a content ETag and honors conditional reads', function (?string $header, int $status) {
    $body = '{"id":1}';
    $etag = '"'.hash('sha256', $body).'"';
    $headers = $header === null ? [] : ['If-None-Match' => str_replace('CURRENT', $etag, $header)];
    $request = foundationApiContractRequest('GET', '', $headers);

    $response = (new ApiContract(new IdempotencyStore()))->handle($request, fn () => response($body, 200));

    expect($response->getStatusCode())->toBe($status)
        ->and($response->headers->get('ETag'))->toBe($etag)
        ->and($response->getContent())->toBe($status === 304 ? '' : $body);
})->with([
    'no condition' => [null, 200],
    'matching tag among alternatives' => ['"old", CURRENT', 304],
    'wildcard' => ['*', 304],
    'different tag' => ['"old"', 200],
]);

it('does not add an ETag to responses without a cacheable representation', function (Closure $responseFactory) {
    $response = (new ApiContract(new IdempotencyStore()))->handle(foundationApiContractRequest('GET'), $responseFactory);

    expect($response->headers->has('ETag'))->toBeFalse();
})->with([
    'no content' => [fn () => response('', 204)],
    'error' => [fn () => response('missing', 404)],
    'empty representation' => [fn () => response('', 200)],
    'streamed representation' => [fn () => new StreamedResponse(static fn () => null)],
]);

it('ignores idempotency headers for reads', function () {
    $response = (new ApiContract(new IdempotencyStore()))->handle(
        foundationApiContractRequest('GET', '', ['Idempotency-Key' => 'invalid key']),
        fn () => response()->json(['read' => true]),
    );

    expect($response->getStatusCode())->toBe(200);
    $this->assertDatabaseCount('api_idempotency_keys', 0);
});

it('allows writes without an idempotency header', function () {
    $response = (new ApiContract(new IdempotencyStore()))->handle(
        foundationApiContractRequest(), fn () => response()->json(['created' => true], 201),
    );

    expect($response->getStatusCode())->toBe(201);
    $this->assertDatabaseCount('api_idempotency_keys', 0);
});

it('rejects malformed idempotency keys before executing a write', function (string $key) {
    expect(fn () => (new ApiContract(new IdempotencyStore()))->handle(
        foundationApiContractRequest('POST', 'request', ['Idempotency-Key' => $key]),
        fn () => throw new LogicException('The write must not execute.'),
    ))->toThrow(HttpException::class, 'A valid Idempotency-Key header is required.');
    $this->assertDatabaseCount('api_idempotency_keys', 0);
})->with(['spaces' => ['not valid'], 'empty' => [''], 'too long' => [str_repeat('x', 129)]]);

it('replays successful writes without executing them again', function (string $method) {
    $middleware = new ApiContract(new IdempotencyStore());
    $headers = ['Idempotency-Key' => 'checkout-1'];
    $first = $middleware->handle(foundationApiContractRequest($method, 'request', $headers), fn () => response()->json(['id' => 1], 201));
    $replay = $middleware->handle(foundationApiContractRequest($method, 'request', $headers), fn () => throw new LogicException('The write must not execute twice.'));

    expect($replay->getStatusCode())->toBe($first->getStatusCode())
        ->and($replay->getContent())->toBe($first->getContent())
        ->and($replay->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and($replay->headers->get('Content-Type'))->toBe('application/json');
    $this->assertDatabaseCount('api_idempotency_keys', 1);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('returns a problem response for conflicting or unfinished requests', function (bool $unfinished) {
    $middleware = new ApiContract(new IdempotencyStore());
    $headers = ['Idempotency-Key' => 'checkout-1'];
    $middleware->handle(foundationApiContractRequest('POST', 'original', $headers), fn () => response()->json(['id' => 1]));
    if ($unfinished) {
        DB::table('api_idempotency_keys')->update(['response_status' => null, 'response_body' => null]);
    }

    $response = $middleware->handle(
        foundationApiContractRequest('POST', $unfinished ? 'original' : 'changed', $headers),
        fn () => throw new LogicException('The write must not execute.'),
    );

    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and(json_decode($response->getContent(), true))->toMatchArray([
            'status' => 409,
            'type' => 'https://liberu.software/problems/'.($unfinished ? 'idempotency-in-progress' : 'idempotency-conflict'),
        ]);
})->with(['unfinished request' => [true], 'different payload' => [false]]);

it('isolates idempotency records across actors and teams', function () {
    $middleware = new ApiContract(new IdempotencyStore());
    foreach ([[1, 1], [2, 1], [1, 2], [3, null]] as [$actorId, $teamId]) {
        $actor = new GenericUser(['id' => $actorId]);
        if ($teamId !== null) {
            $actor->currentTeam = foundationApiContractModel()->forceFill(['id' => $teamId]);
        }
        $request = foundationApiContractRequest('POST', 'request', ['Idempotency-Key' => 'checkout-1']);
        $request->setUserResolver(static fn () => $actor);
        $response = $middleware->handle($request, fn () => response()->json(['created' => true], 201));
        expect($response->headers->has('Idempotency-Replayed'))->toBeFalse();
    }

    $this->assertDatabaseCount('api_idempotency_keys', 4);
});

it('releases idempotency keys after server failures or streamed responses', function (Closure $responseFactory) {
    $middleware = new ApiContract(new IdempotencyStore());
    $headers = ['Idempotency-Key' => 'checkout-1'];
    $middleware->handle(foundationApiContractRequest('POST', 'request', $headers), $responseFactory);

    $this->assertDatabaseCount('api_idempotency_keys', 0);
    $retry = $middleware->handle(foundationApiContractRequest('POST', 'request', $headers), fn () => response()->json(['created' => true], 201));
    expect($retry->getStatusCode())->toBe(201)
        ->and($retry->headers->has('Idempotency-Replayed'))->toBeFalse();
})->with([
    'server failure' => [fn () => response('unavailable', 503)],
    'streamed response' => [fn () => new StreamedResponse(static fn () => null)],
]);

it('propagates downstream exceptions and releases any idempotency key', function (bool $withKey) {
    $headers = $withKey ? ['Idempotency-Key' => 'checkout-1'] : [];
    $failure = new RuntimeException('downstream failure');

    expect(fn () => (new ApiContract(new IdempotencyStore()))->handle(
        foundationApiContractRequest('POST', 'request', $headers), fn () => throw $failure,
    ))->toThrow($failure);
    $this->assertDatabaseCount('api_idempotency_keys', 0);
})->with([true, false]);

it('uses model versions to accept or reject conditional writes', function (?string $updatedAt) {
    $middleware = new ApiContract(new IdempotencyStore());
    $model = foundationApiContractModel($updatedAt);
    $read = $middleware->handle(foundationApiContractRequest('GET', '', [], $model), fn () => response()->json(['id' => 1]));
    $etag = $read->headers->get('ETag');

    $accepted = $middleware->handle(
        foundationApiContractRequest('PATCH', 'request', ['If-Match' => $etag], $model),
        fn () => response()->json(['updated' => true]),
    );
    expect($accepted->getStatusCode())->toBe(200)->and($accepted->headers->get('ETag'))->toBe($etag);

    $model->forceFill(['updated_at' => '2026-02-01 12:00:00']);
    $rejected = $middleware->handle(
        foundationApiContractRequest('PATCH', 'request', ['If-Match' => $etag, 'Idempotency-Key' => 'update-1'], $model),
        fn () => throw new LogicException('The stale update must not execute.'),
    );
    expect($rejected->getStatusCode())->toBe(412)
        ->and(json_decode($rejected->getContent(), true)['type'])->toBe('https://liberu.software/problems/precondition-failed');
    $this->assertDatabaseCount('api_idempotency_keys', 0);
})->with(['timestamped model' => ['2026-01-01 12:00:00'], 'model without a timestamp' => [null]]);

it('rejects conditional writes without a bound model', function () {
    $request = foundationApiContractRequest('PATCH', 'request', ['If-Match' => '*']);
    $route = new Route('PATCH', 'records/{record}', static fn () => null);
    $route->bind($request);
    $request->setRouteResolver(static fn () => $route);

    $response = (new ApiContract(new IdempotencyStore()))->handle($request, fn () => throw new LogicException('The write must not execute.'));

    expect($response->getStatusCode())->toBe(412);
});

function foundationLegacyIdempotencyRequest(?string $key = 'request-1', string $method = 'POST', int $team = 1): Request
{
    $request = Request::create('/checkout', $method, [], [], [], [], 'request');
    if ($key !== null) {
        $request->headers->set('Idempotency-Key', $key);
    }
    $actor = new GenericUser(['id' => 7, 'current_team_id' => $team]);
    $request->setUserResolver(static fn () => $actor);

    return $request;
}

it('registers the legacy alias and the configured API rate limiter', function (string $configuration, int $attempts, int $seconds) {
    config(['api-access.rate_limit' => $configuration]);
    // Hosts can replace the limiter; exercise the module provider lifecycle explicitly.
    (new ApiAccessServiceProvider(app()))->boot();
    $limiter = RateLimiter::limiter('api');
    $request = foundationLegacyIdempotencyRequest();
    $limit = $limiter($request);
    $guest = Request::create('/checkout', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.9']);

    expect(app('router')->getMiddleware()['api.idempotency'])->toBe(Idempotency::class)
        ->and($limit->maxAttempts)->toBe($attempts)
        ->and($limit->decaySeconds)->toBe($seconds)
        ->and($limit->key)->toBe(7)
        ->and($limiter($guest)->key)->toBe('127.0.0.9');
})->with(['configured period' => ['12,2', 12, 120], 'default period' => ['7', 7, 60]]);

it('bypasses storage when a legacy request has no key', function (string $middlewareClass) {
    $response = (new $middlewareClass(new IdempotencyStore()))->handle(
        foundationLegacyIdempotencyRequest(null), fn () => new Response('unkeyed', 200),
    );

    expect($response->getContent())->toBe('unkeyed');
    $this->assertDatabaseCount('api_idempotency_keys', 0);
})->with([Idempotency::class, ReplayIdempotentRequest::class]);

it('bypasses read requests in the legacy idempotency alias', function (string $method) {
    $response = (new Idempotency(new IdempotencyStore()))->handle(
        foundationLegacyIdempotencyRequest('request-1', $method), fn () => new Response('read', 200),
    );

    expect($response->getContent())->toBe('read');
    $this->assertDatabaseCount('api_idempotency_keys', 0);
})->with(['GET', 'HEAD', 'OPTIONS']);

it('preserves each legacy replay header and executes a keyed write once', function (string $middlewareClass, string $header) {
    $middleware = new $middlewareClass(new IdempotencyStore());
    $first = $middleware->handle(foundationLegacyIdempotencyRequest(' request-1 '), fn () => new Response('{"id":1}', 201));
    $replay = $middleware->handle(foundationLegacyIdempotencyRequest(), fn () => throw new LogicException('The write must not execute twice.'));

    expect($first->getStatusCode())->toBe(201)
        ->and($replay->getStatusCode())->toBe(201)
        ->and($replay->getContent())->toBe('{"id":1}')
        ->and($replay->headers->get($header))->toBe('true')
        ->and($replay->headers->get('Content-Type'))->toBe('application/json');
    if ($middlewareClass === Idempotency::class) {
        expect($first->headers->get('Idempotency-Key'))->toBe('request-1');
    }
    $this->assertDatabaseCount('api_idempotency_keys', 1);
})->with([
    'idempotency alias' => [Idempotency::class, 'Idempotency-Replayed'],
    'billing replay alias' => [ReplayIdempotentRequest::class, 'Idempotent-Replayed'],
]);

it('reports in-progress requests without running another write', function (string $middlewareClass) {
    $middleware = new $middlewareClass(new IdempotencyStore());
    $middleware->handle(foundationLegacyIdempotencyRequest(), fn () => response()->json(['id' => 1]));
    DB::table('api_idempotency_keys')->update(['response_status' => null, 'response_body' => null]);

    $response = $middleware->handle(foundationLegacyIdempotencyRequest(), fn () => throw new LogicException('The pending write must not execute again.'));

    expect($response->getStatusCode())->toBe(409);
    if ($middlewareClass === ReplayIdempotentRequest::class) {
        expect($response->headers->get('Retry-After'))->toBe('1');
    }
})->with([Idempotency::class, ReplayIdempotentRequest::class]);

it('isolates replay records when an actor changes teams', function (string $middlewareClass) {
    $middleware = new $middlewareClass(new IdempotencyStore());
    foreach ([1, 2] as $team) {
        $response = $middleware->handle(foundationLegacyIdempotencyRequest('request-1', 'POST', $team), fn () => response()->json(['team' => $team], 201));
        expect(json_decode($response->getContent(), true)['team'])->toBe($team);
    }
    $this->assertDatabaseCount('api_idempotency_keys', 2);
})->with([Idempotency::class, ReplayIdempotentRequest::class]);

it('releases a legacy reservation after exceptions', function (string $middlewareClass) {
    $exception = new RuntimeException('write failed');

    expect(fn () => (new $middlewareClass(new IdempotencyStore()))->handle(
        foundationLegacyIdempotencyRequest(), fn () => throw $exception,
    ))->toThrow($exception);
    $this->assertDatabaseCount('api_idempotency_keys', 0);
})->with([Idempotency::class, ReplayIdempotentRequest::class]);

it('releases legacy reservations after server errors or streamed responses', function (string $middlewareClass, bool $streamed) {
    $response = (new $middlewareClass(new IdempotencyStore()))->handle(
        foundationLegacyIdempotencyRequest(), fn () => $streamed ? new StreamedResponse(static fn () => null) : new Response('failed', 503),
    );

    expect($response->getStatusCode())->toBe($streamed ? 200 : 503);
    $this->assertDatabaseCount('api_idempotency_keys', 0);
})->with([
    'idempotency server error' => [Idempotency::class, false],
    'idempotency stream' => [Idempotency::class, true],
    'billing server error' => [ReplayIdempotentRequest::class, false],
    'billing stream' => [ReplayIdempotentRequest::class, true],
]);

it('stores a request hash and replays the completed response', function () {
    $store = new IdempotencyStore();
    $body = '{"amount":100}';

    expect($store->begin('customer-1', 'checkout-1', $body))->toBeNull();
    $pending = $store->begin('customer-1', 'checkout-1', $body);
    expect($pending->request_hash)->toBe(hash('sha256', $body))
        ->and($pending->response_status)->toBeNull()
        ->and($pending->response_body)->toBeNull();

    $store->complete('customer-1', 'checkout-1', 201, '{"order":"order-1"}');
    $replay = $store->begin('customer-1', 'checkout-1', $body);

    expect($replay->id)->toBe($pending->id)
        ->and($replay->response_status)->toBe(201)
        ->and($replay->response_body)->toBe('{"order":"order-1"}');
    $this->assertDatabaseCount('api_idempotency_keys', 1);
});

it('rejects a different payload without replacing the original request', function () {
    $store = new IdempotencyStore();
    $store->begin('customer-1', 'checkout-1', 'original');

    expect(fn () => $store->begin('customer-1', 'checkout-1', 'changed'))
        ->toThrow(RuntimeException::class, 'Idempotency key was reused with a different request.');

    $this->assertDatabaseHas('api_idempotency_keys', [
        'identity_ref' => 'customer-1',
        'key' => 'checkout-1',
        'request_hash' => hash('sha256', 'original'),
    ]);
});

it('isolates completion and forgetting by both identity and request key', function () {
    $store = new IdempotencyStore();
    $store->begin('customer-1', 'checkout-1', 'one');
    $store->begin('customer-2', 'checkout-1', 'two');
    $store->begin('customer-1', 'checkout-2', 'three');

    $store->complete('customer-1', 'checkout-1', 200, 'completed');

    expect($store->begin('customer-2', 'checkout-1', 'two')->response_status)->toBeNull()
        ->and($store->begin('customer-1', 'checkout-2', 'three')->response_status)->toBeNull();

    $store->forget('customer-1', 'checkout-1');

    $this->assertDatabaseMissing('api_idempotency_keys', ['identity_ref' => 'customer-1', 'key' => 'checkout-1']);
    $this->assertDatabaseCount('api_idempotency_keys', 2);
    expect($store->begin('customer-1', 'checkout-1', 'replacement'))->toBeNull();
});

it('replaces an expired request at the configured expiry boundary', function () {
    config(['api-access.idempotency_hours' => 2]);
    $this->travelTo(now()->startOfSecond());
    $store = new IdempotencyStore();
    $store->begin('customer-1', 'checkout-1', 'original');
    $store->complete('customer-1', 'checkout-1', 200, 'old response');

    $this->travel(2)->hours();

    expect($store->begin('customer-1', 'checkout-1', 'replacement'))->toBeNull();
    $replacement = $store->begin('customer-1', 'checkout-1', 'replacement');
    expect($replacement->request_hash)->toBe(hash('sha256', 'replacement'))
        ->and($replacement->response_status)->toBeNull()
        ->and($replacement->response_body)->toBeNull()
        ->and($replacement->expires_at)->toBe(now()->addHours(2)->toDateTimeString());
    $this->assertDatabaseCount('api_idempotency_keys', 1);
});

it('recovers the winning request when another process inserts the same key', function (bool $samePayload) {
    $winner = (object) [
        'request_hash' => hash('sha256', $samePayload ? 'request' : 'different request'),
        'response_status' => 201,
        'response_body' => 'saved response',
    ];
    $query = Mockery::mock(Builder::class);
    DB::shouldReceive('table')->with('api_idempotency_keys')->andReturn($query);
    $query->shouldReceive('where')->andReturnSelf();
    $query->shouldReceive('lockForUpdate')->andReturnSelf();
    DB::shouldReceive('transaction')->once()->andReturnUsing(fn (Closure $callback) => $callback());
    $query->shouldReceive('first')->twice()->andReturn(null, $winner);
    $query->shouldReceive('insertOrIgnore')->once()->andReturn(0);
    $store = new IdempotencyStore();

    if ($samePayload) {
        expect($store->begin('customer-1', 'checkout-1', 'request'))->toBe($winner);
    } else {
        expect(fn () => $store->begin('customer-1', 'checkout-1', 'request'))
            ->toThrow(RuntimeException::class, 'Idempotency key was reused with a different request.');
    }
})->with(['same request' => [true], 'conflicting request' => [false]]);

it('propagates an insert failure when there is no winning request to replay', function () {
    $failure = new RuntimeException('database unavailable');
    $query = Mockery::mock(Builder::class);
    DB::shouldReceive('table')->with('api_idempotency_keys')->andReturn($query);
    $query->shouldReceive('where')->andReturnSelf();
    $query->shouldReceive('lockForUpdate')->andReturnSelf();
    DB::shouldReceive('transaction')->once()->andReturnUsing(fn (Closure $callback) => $callback());
    $query->shouldReceive('first')->once()->andReturnNull();
    $query->shouldReceive('insertOrIgnore')->once()->andThrow($failure);

    expect(fn () => (new IdempotencyStore())->begin('customer-1', 'checkout-1', 'request'))
        ->toThrow($failure);
});

it('does not claim a reservation when an ignored insert has no winning row', function () {
    $query = Mockery::mock(Builder::class);
    DB::shouldReceive('transaction')->once()->andReturnUsing(fn (Closure $callback) => $callback());
    DB::shouldReceive('table')->with('api_idempotency_keys')->andReturn($query);
    $query->shouldReceive('where')->andReturnSelf();
    $query->shouldReceive('lockForUpdate')->twice()->andReturnSelf();
    $query->shouldReceive('first')->twice()->andReturnNull();
    $query->shouldReceive('insertOrIgnore')->once()->andReturn(0);

    expect(fn () => (new IdempotencyStore())->begin('customer-1', 'checkout-1', 'request'))
        ->toThrow(RuntimeException::class, 'Idempotency request could not be reserved.');
});

it('keeps the requested scope order and removes duplicate scopes', function () {
    expect((new TokenPolicy())->scopes(['write', 'read', 'write'], ['read', 'write', 'admin']))
        ->toBe(['write', 'read']);
});

it('allows a token with no scopes', function () {
    expect((new TokenPolicy())->scopes([], ['read']))->toBe([]);
});

it('rejects any scope outside the allowlist', function () {
    expect(fn () => (new TokenPolicy())->scopes(['read', 'admin'], ['read']))
        ->toThrow(InvalidArgumentException::class, 'Requested token scope is not permitted.');
});

it('preserves an expiry before the configured maximum', function () {
    config(['api-access.maximum_token_days' => 7]);
    $requested = new DateTimeImmutable('+2 days');

    expect((new TokenPolicy())->expiresAt($requested))->toBe($requested);
});

it('caps the expiry at the configured token lifetime', function (?DateTimeImmutable $requested) {
    config(['api-access.maximum_token_days' => 7]);
    $before = new DateTimeImmutable('+7 days');

    $expiry = (new TokenPolicy())->expiresAt($requested);

    $after = new DateTimeImmutable('+7 days');
    expect($expiry >= $before)->toBeTrue()
        ->and($expiry <= $after)->toBeTrue();
})->with([
    'unspecified expiry' => [null],
    'expiry beyond the maximum' => [new DateTimeImmutable('+1 year')],
]);
