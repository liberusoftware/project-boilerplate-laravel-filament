<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Foundation\Profiles\Actions\UpdateProfile;
use Liberu\Foundation\Profiles\Data\ProfileUpdate;
use Liberu\Foundation\Profiles\ProfilesServiceProvider;

it('boots profiles service provider', function (): void {
    $provider = $this->app->register(ProfilesServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(ProfilesServiceProvider::class);
});

it('updates user profile fields and preserves unspecified nullable attributes', function (): void {
    $user = User::factory()->create([
        'name' => 'Initial User',
        'locale' => 'en',
        'timezone' => 'UTC',
        'theme_preference' => 'light',
    ]);

    $action = new UpdateProfile();

    $fullUpdate = new ProfileUpdate(
        name: '  Ada Lovelace  ',
        locale: 'fr',
        timezone: 'Europe/Paris',
        theme: 'dark',
    );

    expect($fullUpdate->name)->toBe('  Ada Lovelace  ')
        ->and($fullUpdate->locale)->toBe('fr')
        ->and($fullUpdate->timezone)->toBe('Europe/Paris')
        ->and($fullUpdate->theme)->toBe('dark');

    $updated = $action->handle($user, $fullUpdate);

    expect($updated->name)->toBe('Ada Lovelace')
        ->and($updated->locale)->toBe('fr')
        ->and($updated->timezone)->toBe('Europe/Paris')
        ->and($updated->theme_preference)->toBe('dark');

    // Partial update with null optional values keeps previously saved preferences
    $partialUpdate = new ProfileUpdate(name: '  Grace Hopper  ');
    expect($partialUpdate->locale)->toBeNull()
        ->and($partialUpdate->timezone)->toBeNull()
        ->and($partialUpdate->theme)->toBeNull();

    $partiallyUpdated = $action->handle($updated, $partialUpdate);

    expect($partiallyUpdated->name)->toBe('Grace Hopper')
        ->and($partiallyUpdated->locale)->toBe('fr')
        ->and($partiallyUpdated->timezone)->toBe('Europe/Paris')
        ->and($partiallyUpdated->theme_preference)->toBe('dark');
});
