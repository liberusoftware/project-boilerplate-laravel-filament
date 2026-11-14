<?php

use Liberu\Foundation\ApplicationCore\Health\ReadinessCheck;
use Liberu\Foundation\ApplicationCore\Health\ReadinessRegistry;

it('serves the named readiness endpoint with the configured release', function () {
    config(['application-core.release' => 'test-release']);

    $this->getJson(route('application.ready', [], false))
        ->assertOk()
        ->assertExactJson(['status' => 'ready', 'checks' => [], 'release' => 'test-release']);
});

it('uses one check snapshot for both its response body and status', function () {
    $check = new class() implements ReadinessCheck
    {
        public int $calls = 0;

        public function name(): string
        {
            return 'database';
        }

        public function ready(): bool
        {
            return ++$this->calls !== 1;
        }
    };
    app(ReadinessRegistry::class)->register($check);

    $this->getJson('/ready')->assertServiceUnavailable()
        ->assertJsonPath('status', 'unavailable')
        ->assertJsonPath('checks.database', false);
    expect($check->calls)->toBe(1);
});
