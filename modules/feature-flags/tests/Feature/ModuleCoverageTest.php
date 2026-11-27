<?php

declare(strict_types=1);

use Liberu\Foundation\FeatureFlags\FeatureFlagsServiceProvider;
use Liberu\Foundation\FeatureFlags\Support\FlagEvaluator;

it('boots feature flags service provider and evaluates every flag rule branch', function (): void {
    $provider = $this->app->register(FeatureFlagsServiceProvider::class, force: true);
    $provider->boot();

    $evaluator = new FlagEvaluator();

    // Disabled by default or explicitly
    expect($evaluator->enabled('billing.v2', [], 'production'))->toBeFalse()
        ->and($evaluator->enabled('billing.v2', ['enabled' => false], 'production'))->toBeFalse();

    // Expired flag returns false
    expect($evaluator->enabled('billing.v2', [
        'enabled' => true,
        'expires_at' => '2000-01-01T00:00:00Z',
    ], 'production'))->toBeFalse();

    // Environment mismatch returns false
    expect($evaluator->enabled('billing.v2', [
        'enabled' => true,
        'expires_at' => '2099-01-01T00:00:00Z',
        'environments' => ['staging'],
    ], 'production'))->toBeFalse();

    // Tenant allowlist returns true even when percentage is 0
    expect($evaluator->enabled('billing.v2', [
        'enabled' => true,
        'environments' => ['production'],
        'tenants' => ['tenant-1'],
        'percentage' => 0,
    ], 'production', 'tenant-1'))->toBeTrue();

    // Actor allowlist returns true even when percentage is 0
    expect($evaluator->enabled('billing.v2', [
        'enabled' => true,
        'actors' => [42],
        'percentage' => 0,
    ], 'production', 'other-tenant', 42))->toBeTrue();

    // Percentage rollout 100% and 0%
    expect($evaluator->enabled('billing.v2', [
        'enabled' => true,
        'percentage' => 100,
    ], 'production'))->toBeTrue()
        ->and($evaluator->enabled('billing.v2', [
            'enabled' => true,
            'percentage' => 0,
        ], 'production', null, 'user-9'))->toBeFalse();
});
