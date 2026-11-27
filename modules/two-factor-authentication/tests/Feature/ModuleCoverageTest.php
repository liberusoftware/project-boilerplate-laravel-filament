<?php

declare(strict_types=1);

use Liberu\Foundation\TwoFactor\TrustedDevices\TrustedDeviceManager;
use Liberu\Foundation\TwoFactor\TwoFactorServiceProvider;

it('covers TwoFactorServiceProvider and TrustedDeviceManager issue, validate, reject, and revokeAll', function (): void {
    $provider = $this->app->register(TwoFactorServiceProvider::class, force: true);
    $provider->register();
    $provider->boot();

    $manager = new TrustedDeviceManager();

    $credential = $manager->issue(501, 'Work Laptop');
    expect($credential)->toContain('.')
        ->and($manager->valid(501, $credential))->toBeTrue()
        ->and($manager->valid(501, 'bad-selector.bad-secret'))->toBeFalse()
        ->and($manager->valid(999, $credential))->toBeFalse();

    [$selector] = explode('.', $credential, 2);
    expect($manager->valid(501, $selector.'.wrong-secret'))->toBeFalse();

    // Revoke all trusted devices for actor 501
    expect($manager->revokeAll(501))->toBe(1)
        ->and($manager->valid(501, $credential))->toBeFalse()
        ->and($manager->revokeAll(501))->toBe(0);
});
