<?php

declare(strict_types=1);

use Liberu\Foundation\SchedulerQueues\Jobs\Concerns\TenantAware;
use Liberu\Foundation\SchedulerQueues\Jobs\Middleware\RestoreTenantContext;
use Liberu\Foundation\SchedulerQueues\SchedulerQueuesServiceProvider;
use Liberu\Foundation\SchedulerQueues\Support\JobPolicy;
use Liberu\Foundation\Tenancy\TenantContext;

it('covers SchedulerQueuesServiceProvider, JobPolicy, TenantAware trait, and RestoreTenantContext middleware', function (): void {
    $provider = $this->app->register(SchedulerQueuesServiceProvider::class, force: true);
    $provider->boot();

    $policy = new JobPolicy();
    expect($policy->backoff(1))->toBe(10)
        ->and($policy->backoff(2))->toBe(20)
        ->and($policy->backoff(10))->toBe(2560)
        ->and($policy->assertIdempotencyKey('  key-123  '))->toBe('key-123');

    expect(fn () => $policy->assertIdempotencyKey(null))
        ->toThrow(InvalidArgumentException::class, 'Queued mutations require an idempotency key.');
    expect(fn () => $policy->assertIdempotencyKey('   '))
        ->toThrow(InvalidArgumentException::class, 'Queued mutations require an idempotency key.');

    TenantContext::set(42);

    $job = new class()
    {
        use TenantAware;

        public function __construct()
        {
            $this->captureTenant();
        }
    };

    expect($job->tenantId)->toBe(42)
        ->and($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(Liberu\Foundation\Tenancy\Jobs\Middleware\RestoreTenantContext::class);

    TenantContext::clear();
    expect(TenantContext::currentId())->toBeNull();

    $observedTenantId = null;
    (new RestoreTenantContext())->handle($job, function () use (&$observedTenantId): void {
        $observedTenantId = TenantContext::currentId();
    });

    expect($observedTenantId)->toBe(42)
        ->and(TenantContext::currentId())->toBeNull();
});
