<?php

declare(strict_types=1);

use Liberu\Analytics\Contracts\AnalyticsDestination;
use Liberu\Analytics\Contracts\AnalyticsDestinationRegistry;
use Liberu\Analytics\Contracts\AnalyticsEvent;
use Liberu\Analytics\Meta\AnalyticsMetaServiceProvider;
use Liberu\Analytics\Meta\Contracts\MetaTransport;
use Liberu\Analytics\Meta\Support\MetaCustomerNormalizer;
use Liberu\Analytics\Meta\Support\MetaDestination;

it('normalizes customer identifiers and delivers events to Meta destination', function (): void {
    $normalizer = new MetaCustomerNormalizer();

    expect($normalizer->email('  User@Example.COM '))->toBe(hash('sha256', 'user@example.com'))
        ->and($normalizer->phone('+44 (0) 20-7946-0958'))->toBe(hash('sha256', '4402079460958'))
        ->and($normalizer->externalId('  cust-99  ', 'secret-salt'))->toBe(hash_hmac('sha256', 'cust-99', 'secret-salt'));

    $event = new AnalyticsEvent(
        id: 'evt-meta-1',
        name: 'Purchase',
        subjectId: '10',
        occurredAt: new DateTimeImmutable('@1700000100'),
        source: 'checkout',
        actorId: null,
        sessionId: null,
        tenantId: null,
        locale: 'en',
        currency: 'USD',
        channel: 'analytics',
        properties: ['value' => 99.5],
    );

    $captured = [];
    $transport = new class($captured) implements MetaTransport
    {
        public function __construct(public array &$captured) {}

        public function send(array $payload): array
        {
            $this->captured[] = $payload;

            return ['events_received' => 1];
        }
    };

    $destination = new MetaDestination($transport);
    expect($destination->name())->toBe('meta')
        ->and($destination->deliver($event))->toBe(['events_received' => 1])
        ->and($captured)->toHaveCount(1)
        ->and($captured[0])->toBe([
            'event_name' => 'Purchase',
            'event_time' => 1700000100,
            'event_id' => 'evt-meta-1',
            'custom_data' => ['value' => 99.5],
        ]);
});

it('boots analytics meta service provider both with and without bound transport', function (): void {
    $provider = $this->app->register(AnalyticsMetaServiceProvider::class, force: true);

    app()->offsetUnset(MetaTransport::class);
    $provider->boot();

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

    $transport = new class() implements MetaTransport
    {
        public function send(array $payload): array
        {
            return ['ok' => true];
        }
    };

    app()->instance(AnalyticsDestinationRegistry::class, $registry);
    app()->instance(MetaTransport::class, $transport);

    $provider->boot();

    expect($registered)->toHaveKey('meta')
        ->and($registered['meta'])->toBeInstanceOf(MetaDestination::class);
});
