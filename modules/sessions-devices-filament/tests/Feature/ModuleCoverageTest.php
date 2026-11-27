<?php

declare(strict_types=1);

use Filament\Panel;
use Illuminate\Support\Facades\DB;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Foundation\Sessions\Queries\SessionReader;
use Liberu\Foundation\SessionsDevicesFilament\Pages\AccountSecurity;
use Liberu\Foundation\SessionsDevicesFilament\SessionsDevicesFilamentPlugin;
use Liberu\Foundation\SessionsDevicesFilament\SessionsDevicesFilamentServiceProvider;

it('boots sessions-devices-filament service provider and registers filament plugin', function (): void {
    $provider = $this->app->register(SessionsDevicesFilamentServiceProvider::class, force: true);
    $provider->boot();

    $plugin = SessionsDevicesFilamentPlugin::make();
    $panel = Panel::make()->id('sessions-test');
    $plugin->register($panel);
    $plugin->boot($panel);

    expect($provider)->toBeInstanceOf(SessionsDevicesFilamentServiceProvider::class)
        ->and(view()->exists('sessions-devices-filament::pages.account-security'))->toBeTrue()
        ->and($plugin)->toBeInstanceOf(SessionsDevicesFilamentPlugin::class)
        ->and($plugin->getId())->toBe('liberu-sessions-devices')
        ->and($panel->getPages())->toContain(AccountSecurity::class);
});

it('checks access, mounts active sessions, and revokes non-current sessions on account security page', function (): void {
    expect(AccountSecurity::canAccess())->toBeFalse();

    $user = User::factory()->create();
    $this->actingAs($user);

    expect(AccountSecurity::canAccess())->toBeTrue();

    $sessionTable = config('sessions-devices.table', 'sessions');
    $currentSessionId = session()->getId();
    $otherSessionId = 'other-device-session-id';

    DB::table($sessionTable)->insert([
        [
            'id' => $currentSessionId,
            'user_id' => $user->id,
            'ip_address' => '192.168.1.42',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode('current'),
            'last_activity' => now()->timestamp,
        ],
        [
            'id' => $otherSessionId,
            'user_id' => $user->id,
            'ip_address' => '10.0.0.15',
            'user_agent' => 'Mobile',
            'payload' => base64_encode('other'),
            'last_activity' => now()->subMinute()->timestamp,
        ],
    ]);

    $reader = app(SessionReader::class);
    $page = new AccountSecurity();
    $page->mount($reader);

    expect($page->sessions)->toHaveCount(2)
        ->and($page->sessions->firstWhere('id', $currentSessionId)?->is_current)->toBeTrue()
        ->and($page->sessions->firstWhere('id', $otherSessionId)?->is_current)->toBeFalse();

    // Revoking another session removes it and refreshes the sessions list
    $page->revoke($otherSessionId, $reader);

    expect($page->sessions)->toHaveCount(1)
        ->and($page->sessions->first()->id)->toBe($currentSessionId);

    // Attempting to revoke the current session leaves it intact
    $page->revoke($currentSessionId, $reader);

    expect($page->sessions)->toHaveCount(1)
        ->and($page->sessions->first()->id)->toBe($currentSessionId);
});
