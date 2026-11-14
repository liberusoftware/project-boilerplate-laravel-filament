<?php

use Liberu\Foundation\DeveloperExperience\Support\EnvironmentDoctor;
use Liberu\Foundation\ModuleManager\Manifest;
use Liberu\Foundation\ModuleManager\ModuleRegistry;

it('accepts loaded extensions and writable runtime paths', function () {
    expect((new EnvironmentDoctor())->inspect(['ctype', 'pdo'], [sys_get_temp_dir()]))->toBe([])
        ->and((new EnvironmentDoctor())->inspect([], []))->toBe([]);
});

it('reports every unavailable extension and runtime path in input order', function () {
    $missing = sys_get_temp_dir().'/foundation-doctor-'.bin2hex(random_bytes(8));

    expect((new EnvironmentDoctor())->inspect(['ctype', 'foundation_missing_extension'], [sys_get_temp_dir(), $missing]))
        ->toBe(['Missing PHP extension: foundation_missing_extension', 'Not writable: '.$missing]);
});

it('registers a doctor command that succeeds for a healthy composition', function () {
    app()->instance(ModuleRegistry::class, new ModuleRegistry([]));

    $this->artisan('foundation:doctor')
        ->expectsOutput('Foundation checks passed.')
        ->assertSuccessful();
});

it('prints environment and module composition errors and exits unsuccessfully', function () {
    $directory = sys_get_temp_dir().'/foundation-doctor-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $originalStorage = app()->storagePath();

    try {
        $manifest = json_decode(file_get_contents(__DIR__.'/../../module.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['name'] = 'diagnostics-fixture';
        $manifest['requires'] = ['php' => '^8.5', 'laravel' => '^13.0', 'packages' => []];
        file_put_contents($directory.'/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        file_put_contents($directory.'/composer.json', json_encode([
            'name' => 'fixture/not-installed',
            'type' => 'liberu-module',
            'extra' => ['liberu' => ['name' => 'wrong-name']],
        ], JSON_THROW_ON_ERROR));

        app()->instance(ModuleRegistry::class, new ModuleRegistry([
            'diagnostics-fixture' => Manifest::fromFile($directory.'/module.json'),
        ]));
        app()->useStoragePath($directory.'/missing-storage');

        $this->artisan('foundation:doctor')
            ->expectsOutput('Not writable: '.$directory.'/missing-storage')
            ->expectsOutput('diagnostics-fixture: Composer extra.liberu.name does not match the manifest.')
            ->expectsOutput('diagnostics-fixture: Composer package is not installed.')
            ->doesntExpectOutput('Foundation checks passed.')
            ->assertFailed();
    } finally {
        app()->useStoragePath($originalStorage);
        unlink($directory.'/module.json');
        unlink($directory.'/composer.json');
        rmdir($directory);
    }
});
