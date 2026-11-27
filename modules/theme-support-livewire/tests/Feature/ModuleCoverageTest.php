<?php

declare(strict_types=1);

use Liberu\Foundation\Theme\Services\ThemeManager;
use Liberu\Foundation\ThemeSupportLivewire\Livewire\ThemeSwitcher;
use Liberu\Foundation\ThemeSupportLivewire\ThemeSupportLivewireServiceProvider;
use Livewire\Livewire;

it('covers ThemeSupportLivewireServiceProvider and ThemeSwitcher mount, render, validation, persistence, and referer boundary', function (): void {
    $provider = $this->app->register(ThemeSupportLivewireServiceProvider::class, force: true);
    $provider->boot();

    $manager = $this->mock(ThemeManager::class);
    $themes = [
        'default' => ['label' => 'Default'],
        'dark' => ['label' => 'Dark'],
    ];

    $manager->shouldReceive('getActiveTheme')->andReturn('default');
    $manager->shouldReceive('getThemes')->andReturn($themes);
    $manager->shouldReceive('themeExists')->with('missing')->andReturn(false);
    $manager->shouldReceive('themeExists')->with('dark')->andReturn(true);
    $manager->shouldReceive('persistTheme')->with('dark')->twice();

    // Invalid theme returns early
    Livewire::test(ThemeSwitcher::class)
        ->assertSet('currentTheme', 'default')
        ->assertSet('availableThemes', $themes)
        ->call('switchTheme', 'missing')
        ->assertNoRedirect();

    // Valid theme with unsafe Referer redirects to '/'
    Livewire::withHeaders(['Referer' => 'https://attacker.example/steal'])
        ->test(ThemeSwitcher::class)
        ->call('switchTheme', 'dark')
        ->assertSet('currentTheme', 'dark')
        ->assertDispatched('theme-changed', theme: 'dark')
        ->assertRedirect('/');

    // Valid theme with safe same-origin Referer redirects back to Referer
    $safeUrl = url('/dashboard?view=compact');
    Livewire::withHeaders(['Referer' => $safeUrl])
        ->test(ThemeSwitcher::class)
        ->call('switchTheme', 'dark')
        ->assertSet('currentTheme', 'dark')
        ->assertRedirect($safeUrl);
});
