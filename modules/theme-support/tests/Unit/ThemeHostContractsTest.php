<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\View\ViewFinderInterface;
use Liberu\Foundation\ModuleManager\ModuleRegistry;
use Liberu\Foundation\Settings\Settings\SiteSettings;
use Liberu\Foundation\Theme\Cache\ThemeCache;
use Liberu\Foundation\Theme\Discovery\ThemeDiscovery;
use Liberu\Foundation\Theme\Exceptions\InvalidTheme;
use Liberu\Foundation\Theme\Manifests\ThemeManifest;
use Liberu\Foundation\Theme\Services\ThemeManager;
use Liberu\Foundation\Theme\Tests\Fixtures\ComposerAvailability;

beforeEach(function () {
    $this->themeContractPaths = [];
});

afterEach(function () {
    ComposerAvailability::$available = true;
    foreach ($this->themeContractPaths as $path) {
        if (is_dir($path)) {
            (new Filesystem())->deleteDirectory($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }
});

function contractThemeManager(object $test): array
{
    $name = 'contract-'.bin2hex(random_bytes(5));
    $manifest = writeCoverageTheme(['name' => $name, 'required_capabilities' => [], 'assets' => ['css' => ['theme.css'], 'js' => ['theme.js']], 'colors' => ['primary' => 'BLUE']]);
    $directory = dirname($manifest);
    file_put_contents($directory.'/theme.js', '');
    mkdir($directory.'/resources/views/layouts', 0777, true);
    mkdir($directory.'/resources/css', 0777, true);
    file_put_contents($directory.'/resources/views/layouts/contract.blade.php', 'contract');
    $tracked = base_path('themes/'.$name);
    mkdir($tracked, 0777, true);
    file_put_contents($tracked.'/theme.css', '');
    file_put_contents($tracked.'/theme.js', '');
    $public = sys_get_temp_dir().'/theme-public-'.bin2hex(random_bytes(5));
    mkdir($public.'/build', 0777, true);
    app()->usePublicPath($public);
    $cache = sys_get_temp_dir().'/theme-host-cache-'.bin2hex(random_bytes(5));
    (new ThemeCache())->write([$name => ThemeManifest::fromFile($manifest)], $cache);
    config(['theme.cache' => true, 'theme.cache_path' => $cache, 'theme.default' => $name, 'theme.fallback' => $name]);
    $test->themeContractPaths = [$directory, $tracked, $public, $cache];
    $manager = new ThemeManager();
    app()->instance(ThemeManager::class, $manager);

    return [$manager, $name, $directory, $tracked, $public];
}

it('renders theme views for authenticated models with no preference attribute loaded', function () {
    $actor = new class() extends User {};
    $actor->forceFill(['id' => 1, 'name' => 'Projected Actor']);
    $actor->exists = true;
    Auth::setUser($actor);
    $previous = Model::preventsAccessingMissingAttributes();
    Model::preventAccessingMissingAttributes();
    try {
        expect(Blade::render('{{ $activeTheme }}'))->toBe('default');
    } finally {
        Model::preventAccessingMissingAttributes($previous);
    }
});

it('updates view themes for valid actor preferences session overrides and site settings', function () {
    $actor = new class() extends User {};
    $actor->forceFill(['id' => 1, 'theme_preference' => 'dark']);
    Auth::setUser($actor);
    expect(Blade::render('{{ $activeTheme }}'))->toBe('dark');
    $actor->forceFill(['theme_preference' => 'missing']);
    session(['theme_preference' => 'default']);
    expect(Blade::render('{{ $activeTheme }}'))->toBe('default');
    session()->forget('theme_preference');
    $settings = $this->createStub(SiteSettings::class);
    $settings->active_theme = 'dark';
    app()->instance(SiteSettings::class, $settings);
    expect(Blade::render('{{ $activeTheme }}'))->toBe('dark');
    $settings->active_theme = 'missing';
    expect(Blade::render('{{ $activeTheme }}'))->toBe('default');
});

it('supports theme helpers real assets layouts and built manifest selection', function () {
    [$manager, $name, $directory, $tracked, $public] = contractThemeManager($this);
    include dirname(__DIR__, 2).'/src/Support/theme_helpers.php';
    expect(theme())->toBe($manager)
        ->and(active_theme())->toBe($name)
        ->and(theme_asset('logo.svg'))->toBe(asset('themes/'.$name.'/logo.svg'))
        ->and(theme_asset('logo.svg', $name))->toBe(asset('themes/'.$name.'/logo.svg'))
        ->and(theme_path())->toBe($directory)
        ->and(theme_views_path())->toBe($directory.'/resources/views')
        ->and(theme_layout('contract'))->toBe('layouts.contract')
        ->and($manager->getThemeAssetPath('css'))->toBe($directory.'/resources/css')
        ->and($manager->getThemePath('missing'))->toEndWith('/missing')
        ->and($manager->getThemeCss())->toBe('themes/'.$name.'/theme.css')
        ->and($manager->getThemeJs())->toBe('themes/'.$name.'/theme.js')
        ->and($manager->hasCustomLayout('contract'))->toBeTrue()
        ->and($manager->primaryColor())->toBe('blue');
    expect($manager->viteHasAsset('missing'))->toBeFalse();
    file_put_contents($public.'/build/manifest.json', json_encode(['themes/'.$name.'/theme.css' => ['file' => 'theme.css']]));
    expect($manager->viteHasAsset('themes/'.$name.'/theme.css'))->toBeTrue()
        ->and($manager->activeCssEntry())->toBe('themes/'.$name.'/theme.css');
    file_put_contents($public.'/build/manifest.json', '[]');
    expect($manager->viteHasAsset('missing'))->toBeFalse()
        ->and($manager->activeCssEntry())->toBe('resources/css/app.css');
    config(['theme.surfaces.invalid' => []]);
    expect($manager->selectForSurface('invalid'))->toBe($name);
});

it('persists only the validated active theme through both persistence entry points', function () {
    [$manager, $name] = contractThemeManager($this);
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('theme_preference')->nullable();
        $table->timestamps();
    });
    $actor = new class() extends User
    {
        protected $table = 'users';

        protected $guarded = [];
    };
    $actor->save();
    Auth::setUser($actor);
    $manager->persistTheme($name);
    set_theme('unavailable');

    expect(active_theme())->toBe($name)
        ->and(session('theme_preference'))->toBe($name)
        ->and($actor->fresh()->theme_preference)->toBe($name);
});

it('runs theme deployment validation cache and clear commands', function () {
    $path = sys_get_temp_dir().'/theme-command-'.bin2hex(random_bytes(5));
    $this->themeContractPaths[] = $path;
    config(['theme.cache_path' => $path]);
    expect(Artisan::call('theme:validate'))->toBe(0)
        ->and(Artisan::output())->toContain('All tracked themes are valid.')
        ->and(Artisan::call('theme:cache'))->toBe(0)
        ->and(is_file($path))->toBeTrue()
        ->and(Artisan::call('theme:clear'))->toBe(0)
        ->and(is_file($path))->toBeFalse();
});

it('leaves custom view finders untouched when registering theme paths', function () {
    $original = View::getFinder();
    $custom = $this->createStub(ViewFinderInterface::class);
    View::setFinder($custom);
    try {
        app(ThemeManager::class)->registerThemePaths();
        expect(View::getFinder())->toBe($custom);
    } finally {
        View::setFinder($original);
    }
});

it('rejects unexpected objects nested inside theme caches', function () {
    $path = tempnam(sys_get_temp_dir(), 'theme-nested-');
    $this->themeContractPaths[] = $path;
    file_put_contents($path, serialize(['nested' => ['payload' => new stdClass()]]));
    expect(fn () => (new ThemeCache())->load($path))->toThrow(InvalidTheme::class, 'invalid');
});

it('discovers tracked theme packages when Composer package metadata is unavailable', function () {
    $package = coverageThemePackage('tracked');
    $root = sys_get_temp_dir().'/theme-no-composer-'.bin2hex(random_bytes(5));
    mkdir($root);
    rename($package, $root.'/tracked');
    $this->themeContractPaths[] = $root;
    ComposerAvailability::$available = false;
    expect(array_keys((new ThemeDiscovery())->discover($root)))->toBe(['tracked']);
});

it('rejects cached inheritance cycles before activating a theme', function () {
    $manifest = ThemeManifest::fromFile(writeCoverageTheme(['name' => 'default', 'parent' => 'default']));
    $this->themeContractPaths[] = $manifest->path;
    $cache = tempnam(sys_get_temp_dir(), 'theme-cycle-');
    $this->themeContractPaths[] = $cache;
    (new ThemeCache())->write(['default' => $manifest], $cache);
    config(['theme.cache' => true, 'theme.cache_path' => $cache]);

    expect(fn () => new ThemeManager())->toThrow(InvalidTheme::class, 'inheritance cycle');
});

it('rejects capability-gated themes when no module registry is bound', function () {
    [$manager, $name, $directory] = contractThemeManager($this);
    $path = $directory.'/theme.json';
    $metadata = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $metadata['required_capabilities'] = ['fixture.unavailable'];
    file_put_contents($path, json_encode($metadata, JSON_THROW_ON_ERROR));
    (new ThemeCache())->write([$name => ThemeManifest::fromFile($path)], config('theme.cache_path'));
    app()->forgetInstance(ModuleRegistry::class);
    app()->offsetUnset(ModuleRegistry::class);

    expect((new ThemeManager())->themeIsCompatible($name))->toBeFalse();
});

it('retains the application stylesheet before built theme assets without duplicating its fallback', function () {
    [$manager, $name, , , $public] = contractThemeManager($this);
    file_put_contents($public.'/build/manifest.json', json_encode([
        'themes/'.$name.'/theme.css' => ['file' => 'theme.css'],
    ]));

    expect($manager->activeEntries())->toBe([
        'resources/css/app.css', 'themes/'.$name.'/theme.css',
        'themes/'.$name.'/theme.js', 'resources/js/app.js',
    ]);

    unlink($public.'/build/manifest.json');

    expect(array_count_values($manager->activeEntries())['resources/css/app.css'])->toBe(1);
});

it('resolves configured legacy preference aliases for actors and sessions while preserving explicit choices', function () {
    config(['theme.preference_aliases' => ['legacy-default' => 'dark']]);
    $actor = new class() extends User {};
    $actor->forceFill(['id' => 1, 'theme_preference' => 'legacy-default']);
    Auth::setUser($actor);

    expect(Blade::render('{{ $activeTheme }}'))->toBe('dark');

    $actor->forceFill(['theme_preference' => null]);
    session(['theme_preference' => 'legacy-default']);

    expect(Blade::render('{{ $activeTheme }}'))->toBe('dark');

    $actor->forceFill(['theme_preference' => 'default']);

    expect(Blade::render('{{ $activeTheme }}'))->toBe('default');
});

it('rejects invalid alias targets and falls through to valid lower-priority preferences', function ($target) {
    config(['theme.preference_aliases' => ['legacy-default' => $target]]);
    $actor = new class() extends User {};
    $actor->forceFill(['id' => 1, 'theme_preference' => 'legacy-default']);
    Auth::setUser($actor);
    session(['theme_preference' => 'dark']);

    expect(Blade::render('{{ $activeTheme }}'))->toBe('dark');
})->with(['missing' => ['unavailable'], 'invalid type' => [[]], 'empty' => ['']]);

it('ignores malformed alias configuration and empty preference values', function () {
    config(['theme.preference_aliases' => 'invalid']);
    $actor = new class() extends User {};
    $actor->forceFill(['id' => 1, 'theme_preference' => 'dark']);
    Auth::setUser($actor);

    expect(Blade::render('{{ $activeTheme }}'))->toBe('dark');

    $actor->forceFill(['theme_preference' => '']);
    session(['theme_preference' => '']);

    expect(Blade::render('{{ $activeTheme }}'))->toBe('default');
});
