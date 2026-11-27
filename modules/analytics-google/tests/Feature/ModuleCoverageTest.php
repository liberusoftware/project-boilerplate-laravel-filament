<?php

declare(strict_types=1);

use Liberu\Analytics\Contracts\AnalyticsDestination;
use Liberu\Analytics\Contracts\AnalyticsDestinationRegistry;
use Liberu\Analytics\Contracts\AnalyticsEvent;
use Liberu\Analytics\Google\AnalyticsGoogleServiceProvider;
use Liberu\Analytics\Google\Contracts\GoogleTransport;
use Liberu\Analytics\Google\Support\GoogleDestination;
use Liberu\Analytics\Google\Support\GoogleEventMapper;

it('maps and delivers analytics events to Google destination and validates event names', function (): void {
    $event = new AnalyticsEvent(
        id: 'evt-google-1',
        name: 'invoice_paid',
        subjectId: '42',
        occurredAt: new DateTimeImmutable('@1700000000'),
        source: 'billing',
        actorId: null,
        sessionId: null,
        tenantId: null,
        locale: 'en',
        currency: 'GBP',
        channel: 'analytics',
        properties: ['amount' => 250],
    );

    $mapper = new GoogleEventMapper();
    $mapped = $mapper->map($event);

    expect($mapped['name'])->toBe('invoice_paid')
        ->and($mapped['params']['amount'])->toBe(250)
        ->and($mapped['params']['currency'])->toBe('GBP')
        ->and($mapped['params']['engagement_time_msec'])->toBe(1)
        ->and($mapped['timestamp_micros'])->toBe($event->occurredAt->format('Uu'));

    $tooLong = new AnalyticsEvent(
        'evt-google-2',
        str_repeat('a', 41),
        '42',
        new DateTimeImmutable(),
        'billing',
        null,
        null,
        null,
        null,
        null,
        'analytics',
    );
    expect(fn () => $mapper->map($tooLong))
        ->toThrow(InvalidArgumentException::class, 'Google event name exceeds 40 characters.');

    $captured = [];
    $transport = new class($captured) implements GoogleTransport
    {
        public function __construct(public array &$captured) {}

        public function send(array $payload): array
        {
            $this->captured[] = $payload;

            return ['status' => 'sent'];
        }
    };

    $destination = new GoogleDestination($mapper, $transport);
    expect($destination->name())->toBe('google')
        ->and($destination->deliver($event))->toBe(['status' => 'sent'])
        ->and($captured)->toHaveCount(1)
        ->and($captured[0]['name'])->toBe('invoice_paid');
});

it('boots analytics google service provider both with and without bound transport', function (): void {
    $provider = $this->app->register(AnalyticsGoogleServiceProvider::class, force: true);

    // Without GoogleTransport bound, boot is a safe no-op
    app()->offsetUnset(GoogleTransport::class);
    $provider->boot();

    // With GoogleTransport and AnalyticsDestinationRegistry bound, registers GoogleDestination
    $registered = [];
    $registry = new class($registered) implements AnalyticsDestinationRegistry
    {
        public function __construct(public array &$registered) {}

        public function register(AnalyticsDestination $destination): void
        {
            $this->registered[$destination->name()] = $destination;
        }

        public function get(string $name): AnalyticsDestination
        {
            return $this->registered[$name];
        }

        public function all(): array
        {
            return $this->registered;
        }
    };

    $transport = new class() implements GoogleTransport
    {
        public function send(array $payload): array
        {
            return ['ok' => true];
        }
    };

    app()->instance(AnalyticsDestinationRegistry::class, $registry);
    app()->instance(GoogleTransport::class, $transport);

    $provider->boot();

    expect($registered)->toHaveKey('google')
        ->and($registered['google'])->toBeInstanceOf(GoogleDestination::class);
});
