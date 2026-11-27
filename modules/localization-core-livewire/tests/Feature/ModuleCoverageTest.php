<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;
use Liberu\Foundation\LocalizationLivewire\Livewire\LanguageSwitcher;
use Liberu\Foundation\LocalizationLivewire\LocalizationLivewireServiceProvider;
use Livewire\Livewire;

it('boots LocalizationLivewireServiceProvider and exercises LanguageSwitcher mount, render, validation, user update, and referer boundary', function (): void {
    $provider = $this->app->register(LocalizationLivewireServiceProvider::class, force: true);
    $provider->boot();

    config()->set('app.supported_locales', [
        'en' => 'English',
        'es' => 'Español',
        'fr' => 'Français',
    ]);
    App::setLocale('en');
    Session::flush();

    // Invalid locale returns early without touching session
    Livewire::test(LanguageSwitcher::class)
        ->assertSet('currentLocale', 'en')
        ->assertSet('availableLocales', [
            'en' => 'English',
            'es' => 'Español',
            'fr' => 'Français',
        ])
        ->call('switchLanguage', 'xx')
        ->assertNoRedirect();

    expect(Session::get('locale'))->toBeNull();

    // Valid locale as guest with unsafe external Referer falls back to '/'
    Livewire::withHeaders(['Referer' => 'https://evil.example/phish'])
        ->test(LanguageSwitcher::class)
        ->call('switchLanguage', 'es')
        ->assertSet('currentLocale', 'es')
        ->assertRedirect('/');

    expect(Session::get('locale'))->toBe('es');

    // Valid locale with safe internal Referer redirects to Referer and updates authenticated Eloquent model
    $user = new class() extends Authenticatable
    {
        public ?string $updatedLocale = null;

        public function update(array $attributes = [], array $options = []): bool
        {
            $this->updatedLocale = isset($attributes['locale']) ? (string) $attributes['locale'] : null;

            return true;
        }
    };

    $this->actingAs($user);
    $safeUrl = url('/settings?tab=locale');

    Livewire::withHeaders(['Referer' => $safeUrl])
        ->test(LanguageSwitcher::class)
        ->call('switchLanguage', 'fr')
        ->assertSet('currentLocale', 'fr')
        ->assertRedirect($safeUrl);

    expect(Session::get('locale'))->toBe('fr')
        ->and($user->updatedLocale)->toBe('fr');
});
