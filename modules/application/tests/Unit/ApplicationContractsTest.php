<?php

use Illuminate\Support\Str;
use Liberu\Foundation\ApplicationCore\Contracts\Clock;
use Liberu\Foundation\ApplicationCore\Contracts\IdentifierFactory;
use Liberu\Foundation\ApplicationCore\Health\ReadinessCheck;
use Liberu\Foundation\ApplicationCore\Health\ReadinessRegistry;
use Liberu\Foundation\ApplicationCore\Support\EnvironmentValidator;
use Liberu\Foundation\ApplicationCore\Support\SystemClock;
use Liberu\Foundation\ApplicationCore\Support\UuidIdentifierFactory;

it('provides a UTC clock independently of the process timezone', function () {
    $timezone = date_default_timezone_get();
    try {
        date_default_timezone_set('Pacific/Honolulu');
        $before = time();
        $clock = app(Clock::class);
        $now = $clock->now();

        expect($clock)->toBeInstanceOf(SystemClock::class)
            ->and(app(Clock::class))->toBe($clock)
            ->and($now->getTimezone()->getName())->toBe('UTC')
            ->and($now->getTimestamp())->toBeGreaterThanOrEqual($before)
            ->and($now->getTimestamp())->toBeLessThanOrEqual(time());
    } finally {
        date_default_timezone_set($timezone);
    }
});

it('creates distinct UUID identifiers through the published factory contract', function () {
    $factory = app(IdentifierFactory::class);
    $identifiers = array_map(fn (): string => $factory->make(), range(1, 10));

    expect($factory)->toBeInstanceOf(UuidIdentifierFactory::class)
        ->and(app(IdentifierFactory::class))->toBe($factory)
        ->and(array_unique($identifiers))->toHaveCount(10);
    foreach ($identifiers as $identifier) {
        expect(Str::isUuid($identifier))->toBeTrue();
    }
});

it('treats an empty readiness registry as ready', function () {
    $registry = app(ReadinessRegistry::class);

    expect($registry->report())->toBe([])
        ->and($registry->ready())->toBeTrue()
        ->and(app(ReadinessRegistry::class))->toBe($registry);
});

it('reports registered checks and rejects duplicate names', function () {
    $registry = new ReadinessRegistry();
    $ready = new class() implements ReadinessCheck
    {
        public function name(): string
        {
            return 'database';
        }

        public function ready(): bool
        {
            return true;
        }
    };
    $unready = new class() implements ReadinessCheck
    {
        public function name(): string
        {
            return 'queue';
        }

        public function ready(): bool
        {
            return false;
        }
    };
    $registry->register($ready);
    $registry->register($unready);

    expect($registry->report())->toBe(['database' => true, 'queue' => false])
        ->and($registry->ready())->toBeFalse()
        ->and(fn () => $registry->register($ready))->toThrow(InvalidArgumentException::class, 'Duplicate readiness check.');
});

it('rejects a missing required configuration value', function () {
    config(['application-core.required_configuration' => ['package.required'], 'package.required' => null]);

    expect(fn () => (new EnvironmentValidator())->validate())
        ->toThrow(RuntimeException::class, 'Required configuration [package.required] is missing.');
});

it('accepts configured false and zero values', function () {
    config(['application-core.required_configuration' => ['package.enabled', 'package.limit'], 'package.enabled' => false, 'package.limit' => 0]);

    expect(fn () => (new EnvironmentValidator())->validate())->not->toThrow(Throwable::class);
});

it('forbids production debugging while allowing explicit development debugging', function (string $environment, bool $debug, bool $allowed) {
    $previous = app()->environment();
    app()['env'] = $environment;
    config(['app.debug' => $debug, 'application-core.required_configuration' => []]);

    try {
        if ($allowed) {
            expect(fn () => (new EnvironmentValidator())->validate())->not->toThrow(Throwable::class);
        } else {
            expect(fn () => (new EnvironmentValidator())->validate())
                ->toThrow(RuntimeException::class, 'APP_DEBUG must be false in production.');
        }
    } finally {
        app()['env'] = $previous;
    }
})->with([
    'production debug' => ['production', true, false],
    'production without debug' => ['production', false, true],
    'development debug' => ['local', true, true],
]);
