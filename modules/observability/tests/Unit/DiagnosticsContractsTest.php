<?php

use Liberu\Foundation\Observability\Contracts\Metrics;
use Liberu\Foundation\Observability\Support\NullMetrics;
use Liberu\Foundation\Observability\Support\Redactor;
use Liberu\Foundation\Observability\Support\SloRegistry;

it('redacts nested sensitive keys without changing ordinary values', function () {
    $input = ['user' => ['name' => 'Alex', 'PASSWORD' => 'private'], 'headers' => ['Authorization' => 'Bearer private', 'cookie' => 'session'], 'items' => [['access_token' => 'private', 'count' => 3]], 'enabled' => false];
    $result = (new Redactor())->redact($input);
    expect($result)->toBe(['user' => ['name' => 'Alex', 'PASSWORD' => '[REDACTED]'], 'headers' => ['Authorization' => '[REDACTED]', 'cookie' => '[REDACTED]'], 'items' => [['access_token' => '[REDACTED]', 'count' => 3]], 'enabled' => false])
        ->and($input['user']['PASSWORD'])->toBe('private');
});

it('honors application-specific sensitive key configuration', function () {
    config(['observability.sensitive_keys' => ['credential']]);
    expect((new Redactor())->redact(['api_credential' => 'private', 'label' => 'public']))->toBe(['api_credential' => '[REDACTED]', 'label' => 'public']);
});

it('registers named objectives once and shares the configured no-op metrics adapter', function () {
    $registry = app(SloRegistry::class);
    expect($registry->all())->toBe([])->and(app(SloRegistry::class))->toBe($registry);
    $registry->register('availability', 0.999, '30d');
    expect($registry->all())->toBe(['availability' => ['target' => 0.999, 'window' => '30d']])
        ->and(fn () => $registry->register('availability', 0.99, '7d'))->toThrow(InvalidArgumentException::class);
    $metrics = app(Metrics::class);
    expect($metrics)->toBeInstanceOf(NullMetrics::class);
    $metrics->increment('requests', 2, ['route' => '/health']);
    $metrics->observe('latency', 0.02, ['route' => '/health']);
});

it('rejects out-of-range and non-finite objective targets', function (float $target) {
    expect(fn () => (new SloRegistry())->register('availability', $target, '30d'))->toThrow(InvalidArgumentException::class);
})->with([-0.1, 0.0, 1.01, INF, -INF, NAN]);
