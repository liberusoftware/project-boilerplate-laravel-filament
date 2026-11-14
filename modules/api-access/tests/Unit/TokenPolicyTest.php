<?php

use Liberu\Foundation\ApiAccess\Support\TokenPolicy;

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
