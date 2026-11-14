<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Liberu\Foundation\ApiAccess\Support\IdempotencyStore;

beforeEach(function () {
    $this->artisan('migrate', ['--database' => 'testing'])->assertSuccessful();
});

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
