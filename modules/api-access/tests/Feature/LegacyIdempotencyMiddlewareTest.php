<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Liberu\Foundation\ApiAccess\Http\Middleware\Idempotency;
use Liberu\Foundation\ApiAccess\Http\Middleware\ReplayIdempotentRequest;
use Liberu\Foundation\ApiAccess\Support\IdempotencyStore;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    $this->artisan('migrate', ['--database' => 'testing'])->assertSuccessful();
});

function legacyIdempotencyRequest(?string $key = 'request-1', string $method = 'POST', int $team = 1): Request
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
    $limiter = RateLimiter::limiter('api');
    $request = legacyIdempotencyRequest();
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
        legacyIdempotencyRequest(null), fn () => new Response('unkeyed', 200),
    );

    expect($response->getContent())->toBe('unkeyed');
    $this->assertDatabaseCount('api_idempotency_keys', 0);
})->with([Idempotency::class, ReplayIdempotentRequest::class]);

it('bypasses read requests in the legacy idempotency alias', function (string $method) {
    $response = (new Idempotency(new IdempotencyStore()))->handle(
        legacyIdempotencyRequest('request-1', $method), fn () => new Response('read', 200),
    );

    expect($response->getContent())->toBe('read');
    $this->assertDatabaseCount('api_idempotency_keys', 0);
})->with(['GET', 'HEAD', 'OPTIONS']);

it('preserves each legacy replay header and executes a keyed write once', function (string $middlewareClass, string $header) {
    $middleware = new $middlewareClass(new IdempotencyStore());
    $first = $middleware->handle(legacyIdempotencyRequest(' request-1 '), fn () => new Response('{"id":1}', 201));
    $replay = $middleware->handle(legacyIdempotencyRequest(), fn () => throw new LogicException('The write must not execute twice.'));

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
    $middleware->handle(legacyIdempotencyRequest(), fn () => response()->json(['id' => 1]));
    DB::table('api_idempotency_keys')->update(['response_status' => null, 'response_body' => null]);

    $response = $middleware->handle(legacyIdempotencyRequest(), fn () => throw new LogicException('The pending write must not execute again.'));

    expect($response->getStatusCode())->toBe(409);
    if ($middlewareClass === ReplayIdempotentRequest::class) {
        expect($response->headers->get('Retry-After'))->toBe('1');
    }
})->with([Idempotency::class, ReplayIdempotentRequest::class]);

it('isolates replay records when an actor changes teams', function (string $middlewareClass) {
    $middleware = new $middlewareClass(new IdempotencyStore());
    foreach ([1, 2] as $team) {
        $response = $middleware->handle(legacyIdempotencyRequest('request-1', 'POST', $team), fn () => response()->json(['team' => $team], 201));
        expect(json_decode($response->getContent(), true)['team'])->toBe($team);
    }
    $this->assertDatabaseCount('api_idempotency_keys', 2);
})->with([Idempotency::class, ReplayIdempotentRequest::class]);

it('releases a legacy reservation after exceptions', function (string $middlewareClass) {
    $exception = new RuntimeException('write failed');

    expect(fn () => (new $middlewareClass(new IdempotencyStore()))->handle(
        legacyIdempotencyRequest(), fn () => throw $exception,
    ))->toThrow($exception);
    $this->assertDatabaseCount('api_idempotency_keys', 0);
})->with([Idempotency::class, ReplayIdempotentRequest::class]);

it('releases legacy reservations after server errors or streamed responses', function (string $middlewareClass, bool $streamed) {
    $response = (new $middlewareClass(new IdempotencyStore()))->handle(
        legacyIdempotencyRequest(), fn () => $streamed ? new StreamedResponse(static fn () => null) : new Response('failed', 503),
    );

    expect($response->getStatusCode())->toBe($streamed ? 200 : 503);
    $this->assertDatabaseCount('api_idempotency_keys', 0);
})->with([
    'idempotency server error' => [Idempotency::class, false],
    'idempotency stream' => [Idempotency::class, true],
    'billing server error' => [ReplayIdempotentRequest::class, false],
    'billing stream' => [ReplayIdempotentRequest::class, true],
]);
