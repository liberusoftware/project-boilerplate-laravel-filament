<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Liberu\Foundation\Observability\Contracts\ObservabilityActor;
use Liberu\Foundation\Observability\ObservabilityServiceProvider;
use Liberu\Foundation\Observability\Providers\TelescopeDashboardServiceProvider;

final class DiagnosticsActor extends GenericUser implements ObservabilityActor
{
    public function isAdmin(): bool
    {
        return (bool) $this->attributes['admin'];
    }
}

beforeEach(fn () => Telescope::stopRecording());

it('limits every diagnostics dashboard gate to administrators', function () {
    $admin = new DiagnosticsActor(['id' => 1, 'admin' => true]);
    $member = new DiagnosticsActor(['id' => 2, 'admin' => false]);
    foreach (['viewPulse', 'viewHorizon', 'viewTelescope'] as $ability) {
        expect(Gate::forUser($admin)->allows($ability))->toBeTrue()
            ->and(Gate::forUser($member)->allows($ability))->toBeFalse()
            ->and(Gate::forUser(null)->allows($ability))->toBeFalse();
    }
});

it('propagates a valid correlation identifier and replaces malformed input', function (?string $incoming) {
    Route::middleware('api')->get('/diagnostics-ping', fn (Request $request) => response()->json([
        'request_id' => $request->attributes->get('correlation_id'), 'context_id' => Context::get('correlation_id'),
    ], 202));
    $response = $this->getJson('/diagnostics-ping', $incoming === null ? [] : ['X-Correlation-ID' => $incoming])->assertStatus(202);
    $id = $response->headers->get('X-Correlation-ID');
    expect(Str::isUuid($id))->toBeTrue()->and($response->json('request_id'))->toBe($id)->and($response->json('context_id'))->toBe($id);
    if ($incoming !== null && Str::isUuid($incoming)) {
        expect($id)->toBe($incoming);
    }
})->with([null, 'invalid-correlation', '188c7e79-bd4e-496a-9ee3-e25e88a41dfa']);

it('retains failed requests and filters routine telemetry outside the local environment', function () {
    $previous = Telescope::$filterUsing;
    Telescope::$filterUsing = [];
    Telescope::flushEntries();
    try {
        (new TelescopeDashboardServiceProvider(app()))->register();
        Telescope::startRecording(false);
        Telescope::recordLog(IncomingEntry::make(['level' => 'info', 'message' => 'Routine entry']));
        expect(Telescope::$entriesQueue)->toBe([])
            ->and(Telescope::$hiddenRequestParameters)->toContain('_token')
            ->and(Telescope::$hiddenRequestHeaders)->toContain('cookie', 'x-csrf-token', 'x-xsrf-token');
        Telescope::recordRequest(IncomingEntry::make(['response_status' => 503]));
        expect(Telescope::$entriesQueue)->toHaveCount(1);
    } finally {
        Telescope::stopRecording();
        Telescope::flushEntries();
        Telescope::$filterUsing = $previous;
    }
});

it('allows routine local diagnostics without adding production-only redaction rules', function () {
    $environment = app()->environment();
    $previous = Telescope::$filterUsing;
    $headers = Telescope::$hiddenRequestHeaders;
    Telescope::$filterUsing = [];
    Telescope::flushEntries();
    try {
        app()->detectEnvironment(fn () => 'local');
        (new TelescopeDashboardServiceProvider(app()))->register();
        Telescope::startRecording(false);
        Telescope::recordLog(IncomingEntry::make(['level' => 'info', 'message' => 'Local entry']));
        expect(Telescope::$entriesQueue)->toHaveCount(1)->and(Telescope::$hiddenRequestHeaders)->toBe($headers);
    } finally {
        app()->detectEnvironment(fn () => $environment);
        Telescope::stopRecording();
        Telescope::flushEntries();
        Telescope::$filterUsing = $previous;
    }
});

it('registers correlation middleware when the HTTP kernel is already initialized', function () {
    $kernel = app(Kernel::class);
    $kernel->setMiddlewareGroups(['web' => [], 'api' => []]);
    (new ObservabilityServiceProvider(app()))->boot();
    Route::middleware('web')->get('/diagnostics-web-ping', fn (Request $request) => response()->json(['id' => $request->attributes->get('correlation_id')]));
    $response = $this->getJson('/diagnostics-web-ping')->assertOk();
    expect(Str::isUuid($response->json('id')))->toBeTrue()
        ->and($response->headers->get('X-Correlation-ID'))->toBe($response->json('id'));
});
