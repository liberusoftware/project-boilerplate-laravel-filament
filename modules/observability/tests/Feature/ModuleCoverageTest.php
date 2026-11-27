<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Liberu\Foundation\Observability\Contracts\Metrics;
use Liberu\Foundation\Observability\Contracts\ObservabilityActor;
use Liberu\Foundation\Observability\Http\Middleware\CorrelationId;
use Liberu\Foundation\Observability\ObservabilityServiceProvider;
use Liberu\Foundation\Observability\Providers\HorizonDashboardServiceProvider;
use Liberu\Foundation\Observability\Providers\TelescopeDashboardServiceProvider;
use Liberu\Foundation\Observability\Support\NullMetrics;
use Liberu\Foundation\Observability\Support\Redactor;
use Liberu\Foundation\Observability\Support\SloRegistry;
use Symfony\Component\HttpFoundation\Response;

it('covers observability service providers, gates, and Telescope local and non-local filtering', function (): void {
    $provider = $this->app->register(ObservabilityServiceProvider::class, force: true);
    $provider->boot();

    $horizon = new HorizonDashboardServiceProvider($this->app);
    $horizon->boot();

    $admin = new class() implements ObservabilityActor
    {
        public function isAdmin(): bool
        {
            return true;
        }
    };

    $member = new class() implements ObservabilityActor
    {
        public function isAdmin(): bool
        {
            return false;
        }
    };

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($member)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewTelescope'))->toBeTrue()
        ->and(Gate::forUser($member)->allows('viewTelescope'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewPulse'))->toBeTrue()
        ->and(Gate::forUser($member)->allows('viewPulse'))->toBeFalse();

    // Non-local Telescope filter and sensitive request details hiding
    $originalEnv = $this->app['env'];
    $originalFilters = Telescope::$filterUsing;

    try {
        $this->app['env'] = 'production';
        Telescope::$filterUsing = [];

        $telescope = new TelescopeDashboardServiceProvider($this->app);
        $telescope->register();

        $normalEntry = IncomingEntry::make(['type' => 'request']);
        $monitoredEntry = IncomingEntry::make(['type' => 'request'])->tags(['monitored']);

        expect(Telescope::$filterUsing)->not->toBeEmpty();
        $filter = end(Telescope::$filterUsing);
        expect($filter($normalEntry))->toBeFalse();

        // Local environment branch returns early in hideSensitiveRequestDetails and allows all entries
        $this->app['env'] = 'local';
        Telescope::$filterUsing = [];

        $localTelescope = new TelescopeDashboardServiceProvider($this->app);
        $localTelescope->register();

        $localFilter = end(Telescope::$filterUsing);
        expect($localFilter($normalEntry))->toBeTrue()
            ->and($localFilter($monitoredEntry))->toBeTrue();
    } finally {
        $this->app['env'] = $originalEnv;
        Telescope::$filterUsing = $originalFilters;
    }
});

it('covers CorrelationId middleware, NullMetrics, Redactor, and SloRegistry', function (): void {
    $middleware = new CorrelationId();

    $validUuid = '550e8400-e29b-41d4-a716-446655440000';
    $requestWithUuid = Request::create('/ping', 'GET', [], [], [], ['HTTP_X_CORRELATION_ID' => $validUuid]);
    $res1 = $middleware->handle($requestWithUuid, static fn (Request $r): Response => new Response('ok'));
    expect($res1->headers->get('X-Correlation-ID'))->toBe($validUuid);

    $requestInvalid = Request::create('/ping', 'GET', [], [], [], ['HTTP_X_CORRELATION_ID' => 'not-a-uuid']);
    $res2 = $middleware->handle($requestInvalid, static fn (Request $r): Response => new Response('ok'));
    expect($res2->headers->get('X-Correlation-ID'))->not->toBe('not-a-uuid');

    $metrics = app(Metrics::class);
    expect($metrics)->toBeInstanceOf(NullMetrics::class);
    $metrics->increment('requests.total', 1, ['env' => 'test']);
    $metrics->observe('requests.duration', 12.5, ['route' => 'home']);

    $redactor = new Redactor();
    $redacted = $redactor->redact([
        'user' => 'alice',
        'password' => 'secret123',
        'nested' => ['api_token' => 'tok_abc', 'safe' => 'ok'],
    ]);
    expect($redacted['user'])->toBe('alice')
        ->and($redacted['password'])->toBe('[REDACTED]')
        ->and($redacted['nested']['api_token'])->toBe('[REDACTED]')
        ->and($redacted['nested']['safe'])->toBe('ok');

    $slo = new SloRegistry();
    $slo->register('api.availability', 0.999, '30d');
    expect($slo->all())->toBe(['api.availability' => ['target' => 0.999, 'window' => '30d']]);

    expect(fn () => $slo->register('api.availability', 0.99, '7d'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $slo->register('bad.zero', 0.0, '7d'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $slo->register('bad.over', 1.5, '7d'))
        ->toThrow(InvalidArgumentException::class);
});
