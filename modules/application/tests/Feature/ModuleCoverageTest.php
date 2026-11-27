<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Liberu\Foundation\ApplicationCore\ApplicationCoreServiceProvider;
use Liberu\Foundation\ApplicationCore\Contracts\Clock;
use Liberu\Foundation\ApplicationCore\Contracts\IdentifierFactory;
use Liberu\Foundation\ApplicationCore\Health\ReadinessCheck;
use Liberu\Foundation\ApplicationCore\Health\ReadinessRegistry;
use Liberu\Foundation\ApplicationCore\Http\Controllers\ReadinessController;
use Liberu\Foundation\ApplicationCore\Http\Middleware\SecurityHeaders;
use Liberu\Foundation\ApplicationCore\Support\EnvironmentValidator;
use Liberu\Foundation\ApplicationCore\Support\SystemClock;
use Liberu\Foundation\ApplicationCore\Support\UuidIdentifierFactory;

it('boots application core provider and exercises clock, identifier factory, and environment validator', function (): void {
    $provider = $this->app->register(ApplicationCoreServiceProvider::class, force: true);
    $provider->register();
    $provider->boot(app(EnvironmentValidator::class));

    $clock = app(Clock::class);
    $ids = app(IdentifierFactory::class);

    expect($clock)->toBeInstanceOf(SystemClock::class)
        ->and($clock->now()->getTimezone()->getName())->toBe('UTC')
        ->and($ids)->toBeInstanceOf(UuidIdentifierFactory::class)
        ->and($ids->make())->not->toBe($ids->make());

    $validator = new EnvironmentValidator();
    config()->set('application-core.required_configuration', ['app.name']);
    $validator->validate();

    config()->set('application-core.required_configuration', ['application-core.definitely_missing_key']);
    expect(fn () => $validator->validate())
        ->toThrow(RuntimeException::class, 'Required configuration [application-core.definitely_missing_key] is missing.');

    config()->set('application-core.required_configuration', []);
    $originalEnv = $this->app['env'];
    $originalDebug = config('app.debug');
    try {
        $this->app['env'] = 'production';
        config()->set('app.debug', true);
        expect(fn () => $validator->validate())
            ->toThrow(RuntimeException::class, 'APP_DEBUG must be false in production.');
    } finally {
        $this->app['env'] = $originalEnv;
        config()->set('app.debug', $originalDebug);
    }
});

it('exercises readiness registry and readiness controller for ready and unavailable states', function (): void {
    config()->set('application-core.release', 'v2026.1');

    $registry = new ReadinessRegistry();
    $passingCheck = new class() implements ReadinessCheck
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
    $failingCheck = new class() implements ReadinessCheck
    {
        public function name(): string
        {
            return 'cache';
        }

        public function ready(): bool
        {
            return false;
        }
    };

    $registry->register($passingCheck);
    expect(fn () => $registry->register($passingCheck))
        ->toThrow(InvalidArgumentException::class, 'Duplicate readiness check.');

    expect($registry->ready())->toBeTrue();

    $controller = new ReadinessController();
    $okResponse = $controller($registry);
    expect($okResponse->getStatusCode())->toBe(200)
        ->and($okResponse->getData(true))->toBe([
            'status' => 'ready',
            'checks' => ['database' => true],
            'release' => 'v2026.1',
        ]);

    $registry->register($failingCheck);
    expect($registry->ready())->toBeFalse();

    $failResponse = $controller($registry);
    expect($failResponse->getStatusCode())->toBe(503)
        ->and($failResponse->getData(true))->toBe([
            'status' => 'unavailable',
            'checks' => ['database' => true, 'cache' => false],
            'release' => 'v2026.1',
        ]);

    app()->instance(ReadinessRegistry::class, $registry);
    $this->get('/ready')->assertStatus(503);
});

it('attaches security headers including HSTS over HTTPS and preserves existing headers', function (): void {
    $middleware = new SecurityHeaders();

    $httpRequest = Request::create('http://example.test/dashboard', 'GET');
    $httpResponse = $middleware->handle($httpRequest, fn (): Response => new Response('ok', 200, ['X-Frame-Options' => 'SAMEORIGIN']));

    expect($httpResponse->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($httpResponse->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($httpResponse->headers->has('Strict-Transport-Security'))->toBeFalse();

    $httpsRequest = Request::create('https://example.test/dashboard', 'GET');
    $httpsResponse = $middleware->handle($httpsRequest, fn (): Response => new Response('ok'));

    expect($httpsResponse->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($httpsResponse->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains');
});
