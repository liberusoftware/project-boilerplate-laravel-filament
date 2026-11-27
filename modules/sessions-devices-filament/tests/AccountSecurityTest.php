<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Panel;
use Illuminate\Support\Facades\DB;
use Liberu\Foundation\Sessions\Queries\SessionReader;
use Liberu\Foundation\SessionsDevicesFilament\Pages\AccountSecurity;
use Liberu\Foundation\SessionsDevicesFilament\SessionsDevicesFilamentPlugin;

it('registers the account security page through the filament plugin', function () {
    $plugin = SessionsDevicesFilamentPlugin::make();
    $panel = Panel::make()->id('sessions-test');

    $plugin->register($panel);
    $plugin->boot($panel);

    expect($plugin->getId())->toBe('liberu-sessions-devices')
        ->and($panel->getPages())->toContain(AccountSecurity::class);
});

it('restricts access to authenticated users and manages active sessions', function () {
    expect(AccountSecurity::canAccess())->toBeFalse();

    $user = User::factory()->create();
    $this->actingAs($user);

    expect(AccountSecurity::canAccess())->toBeTrue();

    $currentSessionId = session()->getId();
    $otherSessionId = 'other-session-id-123';

    DB::table(config('sessions-devices.table', 'sessions'))->insert([
        [
            'id' => $currentSessionId,
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode(''),
            'last_activity' => now()->timestamp,
        ],
        [
            'id' => $otherSessionId,
            'user_id' => $user->getKey(),
            'ip_address' => '10.0.0.5',
            'user_agent' => 'OtherDevice',
            'payload' => base64_encode(''),
            'last_activity' => now()->subMinute()->timestamp,
        ],
    ]);

    $reader = app(SessionReader::class);
    $page = new AccountSecurity();
    $page->mount($reader);

    expect($page->sessions)->toHaveCount(2);

    $page->revoke($otherSessionId, $reader);

    expect($page->sessions)->toHaveCount(1)
        ->and($page->sessions->first()->id)->toBe($currentSessionId);
});
