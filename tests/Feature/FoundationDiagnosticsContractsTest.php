<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Liberu\Foundation\Observability\Contracts\Metrics;
use Liberu\Foundation\Observability\Contracts\ObservabilityActor;
use Liberu\Foundation\Observability\Providers\HorizonDashboardServiceProvider;
use Liberu\Foundation\Observability\Providers\TelescopeDashboardServiceProvider;
use Liberu\Foundation\Observability\Support\NullMetrics;
use Liberu\Foundation\Observability\Support\Redactor;
use Liberu\Foundation\Observability\Support\SloRegistry;

it('propagates consistent correlation identifiers through the hosts real API pipeline', function () {
    Telescope::stopRecording();
    Route::middleware('api')->get('/foundation-diagnostics-contract', fn (Request $request) => response()->json(['id' => $request->attributes->get('correlation_id'), 'context' => Context::get('correlation_id')], 202));
    foreach ([null, 'invalid-id', '188c7e79-bd4e-496a-9ee3-e25e88a41dfa'] as $incoming) {
        $response = $this->getJson('/foundation-diagnostics-contract', $incoming === null ? [] : ['X-Correlation-ID' => $incoming])->assertStatus(202);
        $id = $response->headers->get('X-Correlation-ID');
        expect(Str::isUuid($id))->toBeTrue()->and($response->json('id'))->toBe($id)->and($response->json('context'))->toBe($id);
        if ($incoming !== null && Str::isUuid($incoming)) {
            expect($id)->toBe($incoming);
        }
    }
});

it('connects native application administrator decisions to diagnostics gates', function () {
    $actor = User::factory()->create();
    expect($actor)->toBeInstanceOf(ObservabilityActor::class);
    foreach (['viewPulse', 'viewTelescope', 'viewHorizon'] as $ability) {
        expect(Gate::forUser($actor)->allows($ability))->toBe($actor->isAdmin())
            ->and(Gate::forUser(null)->allows($ability))->toBeFalse();
    }

    // Hosts may replace this gate; verify the module provider lifecycle independently.
    (new HorizonDashboardServiceProvider(app()))->boot();
    expect(Gate::forUser($actor)->allows('viewHorizon'))->toBe($actor->isAdmin())
        ->and(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});

it('rejects invalid objectives and keeps metrics and redaction contracts usable', function () {
    $registry = app(SloRegistry::class);
    $registry->register('host-availability', 0.999, '30d');
    expect($registry->all())->toHaveKey('host-availability')
        ->and(fn () => $registry->register('host-availability', 0.99, '7d'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('invalid-target', NAN, '30d'))->toThrow(InvalidArgumentException::class);
    $metrics = app(Metrics::class);
    expect($metrics)->toBeInstanceOf(NullMetrics::class);
    $metrics->increment('host.request', 1);
    $metrics->observe('host.latency', 0.01);
    expect((new Redactor())->redact(['secret' => 'private', 'nested' => ['token' => 'private', 'name' => 'public']]))
        ->toBe(['secret' => '[REDACTED]', 'nested' => ['token' => '[REDACTED]', 'name' => 'public']]);
});

it('filters routine production diagnostics while allowing local investigation', function () {
    $environment = app()->environment();
    $filters = Telescope::$filterUsing;
    Telescope::flushEntries();
    try {
        Telescope::$filterUsing = [];
        (new TelescopeDashboardServiceProvider(app()))->register();
        Telescope::startRecording(false);
        Telescope::recordLog(IncomingEntry::make(['level' => 'info', 'message' => 'Routine entry']));
        expect(Telescope::$entriesQueue)->toBe([]);
        Telescope::recordRequest(IncomingEntry::make(['response_status' => 503]));
        expect(Telescope::$entriesQueue)->toHaveCount(1);
        Telescope::flushEntries();
        Telescope::$filterUsing = [];
        app()->detectEnvironment(fn () => 'local');
        (new TelescopeDashboardServiceProvider(app()))->register();
        Telescope::recordLog(IncomingEntry::make(['level' => 'info', 'message' => 'Local entry']));
        expect(Telescope::$entriesQueue)->toHaveCount(1);
    } finally {
        app()->detectEnvironment(fn () => $environment);
        Telescope::stopRecording();
        Telescope::flushEntries();
        Telescope::$filterUsing = $filters;
    }
});
