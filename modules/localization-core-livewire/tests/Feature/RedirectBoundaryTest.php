<?php

use Liberu\Foundation\LocalizationLivewire\Livewire\LanguageSwitcher;
use Livewire\Livewire;

test('switcher redirects respect the application URL boundary', function (?string $suffix, bool $allowed): void {
    $base = url('/');
    $headers = $suffix === null ? [] : ['Referer' => $base.$suffix];
    $component = Livewire::test(LanguageSwitcher::class);
    $this->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($component->snapshot, JSON_THROW_ON_ERROR),
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'switchLanguage', 'params' => ['es']]],
        ]],
    ], ['X-Livewire' => 'true', ...$headers])
        ->assertSuccessful()
        ->assertJsonPath('components.0.effects.redirect', $allowed ? $base.$suffix : '/');
})->with([
    'missing referer' => [null, false],
    'application root' => ['', true],
    'application path' => ['/account?tab=preferences', true],
    'application query' => ['?tab=preferences', true],
    'application fragment' => ['#preferences', true],
    'lookalike hostname' => ['.attacker.example/account', false],
    'userinfo hostname' => ['@attacker.example/account', false],
]);
