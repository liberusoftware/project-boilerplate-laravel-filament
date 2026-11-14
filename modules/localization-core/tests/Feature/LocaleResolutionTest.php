<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Liberu\Foundation\Localization\Context\LocaleResolver;
use Liberu\PackageTestbench\TestUser;

it('resolves request session and actor preferences in order while retaining actor timezone', function () {
    $actor = TestUser::factory()->make(['locale' => 'de', 'timezone' => 'Pacific/Auckland']);
    auth()->setUser($actor);
    Session::put('locale', 'fr');
    $resolver = app(LocaleResolver::class);
    expect($resolver->resolve(Request::create('/', 'GET', ['locale' => 'es']))->payload())->toBe(['locale' => 'es', 'timezone' => 'Pacific/Auckland'])
        ->and($resolver->resolve(Request::create('/'))->locale)->toBe('fr');
    Session::forget('locale');
    expect($resolver->resolve(Request::create('/'))->locale)->toBe('de');
});

it('supports team and site preferences and marks RTL locales', function () {
    config(['localization.locales' => ['en' => 'English', 'ar' => 'Arabic'], 'localization.team_locale' => 'ar', 'localization.team_timezone' => 'Asia/Dubai']);
    $resolver = app(LocaleResolver::class);
    $context = $resolver->resolve(Request::create('/'));
    expect($context->locale)->toBe('ar')->and($context->timezone)->toBe('Asia/Dubai')->and($context->direction)->toBe('rtl');
    config(['localization.team_locale' => null, 'localization.team_timezone' => null, 'localization.site_locale' => 'en', 'localization.site_timezone' => 'Europe/London']);
    expect($resolver->resolve(Request::create('/'))->payload())->toBe(['locale' => 'en', 'timezone' => 'Europe/London']);
});

it('falls back safely for unsupported locales and invalid timezones', function () {
    config(['localization.locales' => [], 'localization.site_timezone' => 'Not/A_Timezone']);
    $context = app(LocaleResolver::class)->resolve(Request::create('/', 'GET', ['locale' => ['invalid']]));
    expect($context->locale)->toBe('en')->and($context->timezone)->toBe('UTC')->and($context->direction)->toBe('ltr');
});

it('accepts strictly projected Eloquent users without optional preference columns', function (array $columns, string $locale) {
    $actor = TestUser::factory()->create(['locale' => 'de']);
    $projected = TestUser::query()->select($columns)->findOrFail($actor->id);
    auth()->setUser($projected);
    $previous = Model::preventsAccessingMissingAttributes();
    Model::preventAccessingMissingAttributes();
    try {
        $context = app(LocaleResolver::class)->resolve(Request::create('/'));
        expect($context->locale)->toBe($locale)->and($context->timezone)->toBe('UTC');
    } finally {
        Model::preventAccessingMissingAttributes($previous);
    }
})->with([[['id', 'name'], 'en'], [['id', 'name', 'locale'], 'de']]);

it('reads preferences from non-Eloquent authenticatable users', function () {
    auth()->setUser(new GenericUser(['id' => 7, 'locale' => 'es', 'timezone' => 'Europe/Paris']));
    expect(app(LocaleResolver::class)->resolve(Request::create('/'))->payload())->toBe(['locale' => 'es', 'timezone' => 'Europe/Paris']);
});
