<?php

use Liberu\Analytics\Contracts\AnalyticsDestinationRegistry;
use Liberu\Analytics\Contracts\AnalyticsEvent;
use Liberu\Analytics\Core\Support\DestinationRegistry;
use Liberu\Analytics\Core\Support\EventRouter;
use Liberu\Analytics\Core\Support\EventSanitizer;
use Liberu\Analytics\Google\AnalyticsGoogleServiceProvider;
use Liberu\Analytics\Google\Contracts\GoogleTransport;
use Liberu\Analytics\Google\Support\GoogleDestination;
use Liberu\Analytics\Google\Support\GoogleEventMapper;
use Liberu\Analytics\Meta\AnalyticsMetaServiceProvider;
use Liberu\Analytics\Meta\Contracts\MetaTransport;
use Liberu\Analytics\Meta\Support\MetaCustomerNormalizer;
use Liberu\Analytics\Meta\Support\MetaDestination;

it('registers configured analytics destinations and routes only consented allowed events', function (): void {
    $registry = app(AnalyticsDestinationRegistry::class);
    expect($registry)->toBe(app(DestinationRegistry::class));
    $registryBefore = $registry->all();
    $google = Mockery::mock(GoogleTransport::class);
    $meta = Mockery::mock(MetaTransport::class);
    $at = new DateTimeImmutable('2026-01-01T12:34:56.123456Z');
    $event = new AnalyticsEvent('host-event', 'purchase', '1', $at, 'host', null, null, null, 'en', 'USD', 'analytics', ['value' => 123]);
    $google->shouldReceive('send')->once()->with([
        'name' => 'purchase', 'params' => ['value' => 123, 'currency' => 'USD', 'engagement_time_msec' => 1], 'timestamp_micros' => $at->format('Uu'),
    ])->andReturn(['status' => 'delivered', 'destination' => 'google']);
    $meta->shouldReceive('send')->once()->with([
        'event_name' => 'purchase', 'event_time' => $at->getTimestamp(), 'event_id' => 'host-event', 'custom_data' => ['value' => 123],
    ])->andReturn(['status' => 'delivered', 'destination' => 'meta']);
    app()->instance(GoogleTransport::class, $google);
    app()->instance(MetaTransport::class, $meta);
    (new AnalyticsGoogleServiceProvider(app()))->boot();
    (new AnalyticsMetaServiceProvider(app()))->boot();
    expect($registry->get('google'))->toBeInstanceOf(GoogleDestination::class)
        ->and($registry->get('meta'))->toBeInstanceOf(MetaDestination::class)
        ->and($registry->all())->toHaveKeys(['google', 'meta']);
    $router = app(EventRouter::class);
    expect($router->route($event, [], ['google', 'meta']))->toBe(['status' => 'suppressed', 'reason' => 'consent'])
        ->and($router->route($event, ['marketing'], ['google', 'meta']))->toBe(['status' => 'suppressed', 'reason' => 'consent'])
        ->and($router->route($event, ['analytics'], []))->toBe([])
        ->and($router->route($event, ['analytics'], ['google']))->toBe(['google' => ['status' => 'delivered', 'destination' => 'google']])
        ->and($router->route($event, ['analytics'], ['meta']))->toBe(['meta' => ['status' => 'delivered', 'destination' => 'meta']]);
    expect(fn () => $registry->register($registry->get('google')))->toThrow(InvalidArgumentException::class, 'Duplicate analytics destination.')
        ->and(fn () => $registry->get('unregistered-host-destination'))->toThrow(InvalidArgumentException::class, 'Unknown analytics destination [unregistered-host-destination].')
        ->and($registryBefore)->not->toHaveKeys(['google', 'meta']);
});

it('allows strictly necessary events without optional grants and rejects oversized Google names', function (): void {
    $at = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $necessary = new AnalyticsEvent('host-required', 'required', '1', $at, 'host', null, null, null, null, null, 'strictly-necessary');
    expect(app(EventRouter::class)->route($necessary, [], []))->toBe([]);
    $tooLong = new AnalyticsEvent('host-long', str_repeat('a', 41), '1', $at, 'host', null, null, null, null, null, 'analytics');
    expect(fn () => app(GoogleEventMapper::class)->map($tooLong))->toThrow(InvalidArgumentException::class, 'Google event name exceeds 40 characters.');
});

it('sanitizes and normalizes analytics identities before delivery', function (): void {
    $sanitizer = app(EventSanitizer::class);
    expect($sanitizer->sanitize(['value' => 10, 'password' => 'private', 'email' => 'private@example.test'], ['value']))->toBe(['value' => 10])
        ->and($sanitizer->pseudonymize(null, 'tenant-salt'))->toBeNull()
        ->and($sanitizer->pseudonymize(' USER@EXAMPLE.TEST ', 'tenant-salt'))->toBe(hash_hmac('sha256', 'user@example.test', 'tenant-salt'))
        ->and($sanitizer->pseudonymize('user@example.test', 'other-tenant-salt'))->not->toBe($sanitizer->pseudonymize('user@example.test', 'tenant-salt'));
    $normalizer = app(MetaCustomerNormalizer::class);
    expect($normalizer->email(' USER@EXAMPLE.TEST '))->toBe(hash('sha256', 'user@example.test'))
        ->and($normalizer->phone('+44 (123) 456-7890'))->toBe(hash('sha256', '441234567890'))
        ->and($normalizer->externalId(' user-123 ', 'tenant-salt'))->toBe(hash_hmac('sha256', 'user-123', 'tenant-salt'));
});
