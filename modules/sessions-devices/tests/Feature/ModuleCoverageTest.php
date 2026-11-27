<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Liberu\Foundation\Sessions\Queries\SessionReader;
use Liberu\Foundation\Sessions\SessionsDevicesServiceProvider;

it('covers SessionsDevicesServiceProvider and SessionReader listing, IP summarization, and revocation', function (): void {
    $provider = $this->app->register(SessionsDevicesServiceProvider::class, force: true);
    $provider->register();
    $provider->boot();

    $table = config('sessions-devices.table', 'sessions');
    $now = time();

    DB::table($table)->insert([
        [
            'id' => 'sess-current',
            'user_id' => 101,
            'ip_address' => '192.168.1.42',
            'user_agent' => 'Mozilla/5.0',
            'payload' => 'a:0:{}',
            'last_activity' => $now,
        ],
        [
            'id' => 'sess-ipv6',
            'user_id' => 101,
            'ip_address' => '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            'user_agent' => 'Safari/17',
            'payload' => 'a:0:{}',
            'last_activity' => $now - 60,
        ],
        [
            'id' => 'sess-null-ip',
            'user_id' => 101,
            'ip_address' => null,
            'user_agent' => 'CLI',
            'payload' => 'a:0:{}',
            'last_activity' => $now - 120,
        ],
        [
            'id' => 'sess-other-user',
            'user_id' => 202,
            'ip_address' => '10.0.0.5',
            'user_agent' => 'Firefox',
            'payload' => 'a:0:{}',
            'last_activity' => $now,
        ],
    ]);

    $reader = new SessionReader();
    $sessions = $reader->forActor(101, 'sess-current')->keyBy('id');

    expect($sessions)->toHaveCount(3)
        ->and($sessions['sess-current']->is_current)->toBeTrue()
        ->and($sessions['sess-current']->ip_address)->toBe('192.168.1.0')
        ->and($sessions['sess-ipv6']->is_current)->toBeFalse()
        ->and($sessions['sess-ipv6']->ip_address)->toBe('2001:0db8:85a3:0000:0000:8a2e:0370:0')
        ->and($sessions['sess-null-ip']->ip_address)->toBeNull();

    // Cannot revoke current session
    expect($reader->revoke(101, 'sess-current', 'sess-current'))->toBeFalse();

    // Can revoke a non-current session
    expect($reader->revoke(101, 'sess-ipv6', 'sess-current'))->toBeTrue()
        ->and($reader->revoke(101, 'sess-ipv6', 'sess-current'))->toBeFalse();

    // Revoke others removes remaining non-current sessions for actor 101 only
    expect($reader->revokeOthers(101, 'sess-current'))->toBe(1)
        ->and($reader->forActor(101, 'sess-current'))->toHaveCount(1)
        ->and($reader->forActor(202))->toHaveCount(1);
});
