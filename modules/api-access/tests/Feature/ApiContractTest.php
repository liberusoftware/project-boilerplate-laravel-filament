<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Liberu\Foundation\ApiAccess\Http\Middleware\ApiContract;
use Liberu\Foundation\ApiAccess\Support\IdempotencyStore;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->artisan('migrate', ['--database' => 'testing'])->assertSuccessful();
});

function apiContractRequest(string $method = 'POST', string $body = 'request', array $headers = [], ?Model $model = null): Request
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

function apiContractModel(?string $updatedAt = '2026-01-01 12:00:00'): Model
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
    $request = apiContractRequest('GET', '', $headers);

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
    $response = (new ApiContract(new IdempotencyStore()))->handle(apiContractRequest('GET'), $responseFactory);

    expect($response->headers->has('ETag'))->toBeFalse();
})->with([
    'no content' => [fn () => response('', 204)],
    'error' => [fn () => response('missing', 404)],
    'empty representation' => [fn () => response('', 200)],
    'streamed representation' => [fn () => new StreamedResponse(static fn () => null)],
]);

it('ignores idempotency headers for reads', function () {
    $response = (new ApiContract(new IdempotencyStore()))->handle(
        apiContractRequest('GET', '', ['Idempotency-Key' => 'invalid key']),
        fn () => response()->json(['read' => true]),
    );

    expect($response->getStatusCode())->toBe(200);
    $this->assertDatabaseCount('api_idempotency_keys', 0);
});

it('allows writes without an idempotency header', function () {
    $response = (new ApiContract(new IdempotencyStore()))->handle(
        apiContractRequest(), fn () => response()->json(['created' => true], 201),
    );

    expect($response->getStatusCode())->toBe(201);
    $this->assertDatabaseCount('api_idempotency_keys', 0);
});

it('rejects malformed idempotency keys before executing a write', function (string $key) {
    expect(fn () => (new ApiContract(new IdempotencyStore()))->handle(
        apiContractRequest('POST', 'request', ['Idempotency-Key' => $key]),
        fn () => throw new LogicException('The write must not execute.'),
    ))->toThrow(HttpException::class, 'A valid Idempotency-Key header is required.');
    $this->assertDatabaseCount('api_idempotency_keys', 0);
})->with(['spaces' => ['not valid'], 'empty' => [''], 'too long' => [str_repeat('x', 129)]]);

it('replays successful writes without executing them again', function (string $method) {
    $middleware = new ApiContract(new IdempotencyStore());
    $headers = ['Idempotency-Key' => 'checkout-1'];
    $first = $middleware->handle(apiContractRequest($method, 'request', $headers), fn () => response()->json(['id' => 1], 201));
    $replay = $middleware->handle(apiContractRequest($method, 'request', $headers), fn () => throw new LogicException('The write must not execute twice.'));

    expect($replay->getStatusCode())->toBe($first->getStatusCode())
        ->and($replay->getContent())->toBe($first->getContent())
        ->and($replay->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and($replay->headers->get('Content-Type'))->toBe('application/json');
    $this->assertDatabaseCount('api_idempotency_keys', 1);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('returns a problem response for conflicting or unfinished requests', function (bool $unfinished) {
    $middleware = new ApiContract(new IdempotencyStore());
    $headers = ['Idempotency-Key' => 'checkout-1'];
    $middleware->handle(apiContractRequest('POST', 'original', $headers), fn () => response()->json(['id' => 1]));
    if ($unfinished) {
        DB::table('api_idempotency_keys')->update(['response_status' => null, 'response_body' => null]);
    }

    $response = $middleware->handle(
        apiContractRequest('POST', $unfinished ? 'original' : 'changed', $headers),
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
            $actor->currentTeam = apiContractModel()->forceFill(['id' => $teamId]);
        }
        $request = apiContractRequest('POST', 'request', ['Idempotency-Key' => 'checkout-1']);
        $request->setUserResolver(static fn () => $actor);
        $response = $middleware->handle($request, fn () => response()->json(['created' => true], 201));
        expect($response->headers->has('Idempotency-Replayed'))->toBeFalse();
    }

    $this->assertDatabaseCount('api_idempotency_keys', 4);
});

it('releases idempotency keys after server failures or streamed responses', function (Closure $responseFactory) {
    $middleware = new ApiContract(new IdempotencyStore());
    $headers = ['Idempotency-Key' => 'checkout-1'];
    $middleware->handle(apiContractRequest('POST', 'request', $headers), $responseFactory);

    $this->assertDatabaseCount('api_idempotency_keys', 0);
    $retry = $middleware->handle(apiContractRequest('POST', 'request', $headers), fn () => response()->json(['created' => true], 201));
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
        apiContractRequest('POST', 'request', $headers), fn () => throw $failure,
    ))->toThrow($failure);
    $this->assertDatabaseCount('api_idempotency_keys', 0);
})->with([true, false]);

it('uses model versions to accept or reject conditional writes', function (?string $updatedAt) {
    $middleware = new ApiContract(new IdempotencyStore());
    $model = apiContractModel($updatedAt);
    $read = $middleware->handle(apiContractRequest('GET', '', [], $model), fn () => response()->json(['id' => 1]));
    $etag = $read->headers->get('ETag');

    $accepted = $middleware->handle(
        apiContractRequest('PATCH', 'request', ['If-Match' => $etag], $model),
        fn () => response()->json(['updated' => true]),
    );
    expect($accepted->getStatusCode())->toBe(200)->and($accepted->headers->get('ETag'))->toBe($etag);

    $model->forceFill(['updated_at' => '2026-02-01 12:00:00']);
    $rejected = $middleware->handle(
        apiContractRequest('PATCH', 'request', ['If-Match' => $etag, 'Idempotency-Key' => 'update-1'], $model),
        fn () => throw new LogicException('The stale update must not execute.'),
    );
    expect($rejected->getStatusCode())->toBe(412)
        ->and(json_decode($rejected->getContent(), true)['type'])->toBe('https://liberu.software/problems/precondition-failed');
    $this->assertDatabaseCount('api_idempotency_keys', 0);
})->with(['timestamped model' => ['2026-01-01 12:00:00'], 'model without a timestamp' => [null]]);

it('rejects conditional writes without a bound model', function () {
    $request = apiContractRequest('PATCH', 'request', ['If-Match' => '*']);
    $route = new Route('PATCH', 'records/{record}', static fn () => null);
    $route->bind($request);
    $request->setRouteResolver(static fn () => $route);

    $response = (new ApiContract(new IdempotencyStore()))->handle($request, fn () => throw new LogicException('The write must not execute.'));

    expect($response->getStatusCode())->toBe(412);
});
