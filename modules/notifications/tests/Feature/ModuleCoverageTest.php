<?php

declare(strict_types=1);

use Liberu\Foundation\Notifications\NotificationsServiceProvider;
use Liberu\Foundation\Notifications\Support\DeliveryRetry;
use Liberu\Foundation\Notifications\Support\NotificationPolicy;

it('boots notifications service provider', function (): void {
    $provider = $this->app->register(NotificationsServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(NotificationsServiceProvider::class);
});

it('calculates exponential delivery retry delays and exhaustion threshold', function (): void {
    $retry = new DeliveryRetry();

    expect($retry->delay(0))->toBe(60)
        ->and($retry->delay(1))->toBe(60)
        ->and($retry->delay(2))->toBe(120)
        ->and($retry->delay(3))->toBe(240)
        ->and($retry->delay(5))->toBe(960)
        ->and($retry->delay(9))->toBe(15360)
        ->and($retry->delay(20))->toBe(15360)
        ->and($retry->exhausted(1))->toBeFalse()
        ->and($retry->exhausted(7))->toBeFalse()
        ->and($retry->exhausted(8))->toBeTrue()
        ->and($retry->exhausted(12))->toBeTrue();
});

it('evaluates notification channel filtering and quiet hours windows', function (): void {
    $policy = new NotificationPolicy();

    // Channel intersection and operational fallback
    expect($policy->channels(['mail', 'database', 'sms'], ['database', 'mail']))
        ->toBe(['mail', 'database'])
        ->and($policy->channels(['sms'], ['mail', 'database'], false))
        ->toBe([])
        ->and($policy->channels(['sms'], ['mail', 'database'], true))
        ->toBe(['database'])
        ->and($policy->channels(['mail'], ['mail', 'database'], true))
        ->toBe(['mail']);

    // Quiet hours: null boundaries return false
    $noon = new DateTimeImmutable('2026-08-01 12:00:00');
    expect($policy->isQuiet($noon, null, '07:00'))->toBeFalse()
        ->and($policy->isQuiet($noon, '22:00', null))->toBeFalse()
        ->and($policy->isQuiet($noon, null, null))->toBeFalse();

    // Quiet hours: same-day window (09:00 - 17:00)
    expect($policy->isQuiet(new DateTimeImmutable('2026-08-01 08:59:00'), '09:00', '17:00'))->toBeFalse()
        ->and($policy->isQuiet(new DateTimeImmutable('2026-08-01 09:00:00'), '09:00', '17:00'))->toBeTrue()
        ->and($policy->isQuiet(new DateTimeImmutable('2026-08-01 13:30:00'), '09:00', '17:00'))->toBeTrue()
        ->and($policy->isQuiet(new DateTimeImmutable('2026-08-01 17:00:00'), '09:00', '17:00'))->toBeFalse();

    // Quiet hours: overnight window (22:00 - 07:00)
    expect($policy->isQuiet(new DateTimeImmutable('2026-08-01 21:59:00'), '22:00', '07:00'))->toBeFalse()
        ->and($policy->isQuiet(new DateTimeImmutable('2026-08-01 22:00:00'), '22:00', '07:00'))->toBeTrue()
        ->and($policy->isQuiet(new DateTimeImmutable('2026-08-01 02:30:00'), '22:00', '07:00'))->toBeTrue()
        ->and($policy->isQuiet(new DateTimeImmutable('2026-08-01 07:00:00'), '22:00', '07:00'))->toBeFalse();
});
