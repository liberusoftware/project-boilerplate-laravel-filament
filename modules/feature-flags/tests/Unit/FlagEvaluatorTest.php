<?php

use Liberu\Foundation\FeatureFlags\Support\FlagEvaluator;

it('keeps absent and disabled flags off even for targeted subjects', function () {
    $evaluator = new FlagEvaluator();
    expect($evaluator->enabled('release', [], 'production'))->toBeFalse()
        ->and($evaluator->enabled('release', ['enabled' => false, 'actors' => [7]], 'production', actor: 7))->toBeFalse();
});

it('applies expiry and environment restrictions before explicit targeting', function () {
    $evaluator = new FlagEvaluator();
    expect($evaluator->enabled('release', ['enabled' => true, 'expires_at' => '2000-01-01', 'actors' => [7]], 'production', actor: 7))->toBeFalse()
        ->and($evaluator->enabled('release', ['enabled' => true, 'environments' => ['staging'], 'tenants' => [4]], 'production', 4))->toBeFalse()
        ->and($evaluator->enabled('release', ['enabled' => true, 'expires_at' => '2999-01-01', 'environments' => ['production']], 'production'))->toBeTrue();
});

it('matches explicit tenant and actor targets strictly without coercing identifiers', function () {
    $evaluator = new FlagEvaluator();
    $rule = ['enabled' => true, 'percentage' => 0, 'tenants' => [4], 'actors' => ['7']];
    expect($evaluator->enabled('release', $rule, 'production', 4))->toBeTrue()
        ->and($evaluator->enabled('release', $rule, 'production', '4'))->toBeFalse()
        ->and($evaluator->enabled('release', $rule, 'production', actor: '7'))->toBeTrue()
        ->and($evaluator->enabled('release', $rule, 'production', actor: 7))->toBeFalse();
});

it('clamps rollout limits and defaults enabled flags to full rollout', function (int $percentage, bool $expected) {
    $evaluator = new FlagEvaluator();
    expect($evaluator->enabled('release', ['enabled' => true, 'percentage' => $percentage], 'production'))->toBe($expected)
        ->and($evaluator->enabled('release', ['enabled' => true], 'production'))->toBeTrue();
})->with([[-10, false], [0, false], [100, true], [150, true]]);

it('keeps cohorts stable and uses actor identity ahead of tenant identity', function () {
    $evaluator = new FlagEvaluator();
    $rule = ['enabled' => true, 'percentage' => 35];
    $first = array_map(fn ($id) => $evaluator->enabled('release', $rule, 'production', 1, $id), range(1, 500));
    $repeated = array_map(fn ($id) => $evaluator->enabled('release', $rule, 'production', 999, $id), range(1, 500));
    $otherFlag = array_map(fn ($id) => $evaluator->enabled('another-release', $rule, 'production', 1, $id), range(1, 500));
    expect($repeated)->toBe($first)->and($otherFlag)->not->toBe($first)
        ->and(count(array_filter($first)))->toBeGreaterThan(100)->toBeLessThan(250)
        ->and($evaluator->enabled('release', $rule, 'production'))->toBe($evaluator->enabled('release', $rule, 'production'));
});
