<?php

declare(strict_types=1);

use Liberu\Foundation\DeveloperExperience\DeveloperExperienceServiceProvider;
use Liberu\Foundation\DeveloperExperience\Support\EnvironmentDoctor;
use Liberu\Foundation\ModuleManager\Manifest;
use Liberu\Foundation\ModuleManager\ModuleRegistry;

it('boots developer experience provider and inspects environment extensions and paths', function (): void {
    $provider = $this->app->register(DeveloperExperienceServiceProvider::class, force: true);
    $provider->boot();

    $doctor = new EnvironmentDoctor();
    $missingPath = sys_get_temp_dir().'/dx-cov-missing-'.bin2hex(random_bytes(8));

    expect($doctor->inspect(['ctype', 'pdo'], [sys_get_temp_dir()]))->toBe([])
        ->and($doctor->inspect(['dx_nonexistent_ext'], [$missingPath]))->toBe([
            'Missing PHP extension: dx_nonexistent_ext',
            'Not writable: '.$missingPath,
        ]);
});

it('runs foundation:doctor command for both passing and failing environments', function (): void {
    $originalRegistry = app(ModuleRegistry::class);

    try {
        app()->instance(ModuleRegistry::class, new ModuleRegistry([]));
        $this->artisan('foundation:doctor')
            ->expectsOutput('Foundation checks passed.')
            ->assertSuccessful();

        $dir = sys_get_temp_dir().'/dx-cov-'.bin2hex(random_bytes(8));
        mkdir($dir);
        $originalStorage = app()->storagePath();

        try {
            $manifest = json_decode((string) file_get_contents(__DIR__.'/../../module.json'), true, flags: JSON_THROW_ON_ERROR);
            $manifest['name'] = 'dx-cov-fixture';
            $manifest['requires'] = ['php' => '^8.5', 'laravel' => '^13.0', 'packages' => []];
            file_put_contents($dir.'/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            file_put_contents($dir.'/composer.json', json_encode([
                'name' => 'fixture/dx-cov-missing',
                'type' => 'liberu-module',
                'extra' => ['liberu' => ['name' => 'mismatch']],
            ], JSON_THROW_ON_ERROR));

            app()->instance(ModuleRegistry::class, new ModuleRegistry([
                'dx-cov-fixture' => Manifest::fromFile($dir.'/module.json'),
            ]));
            app()->useStoragePath($dir.'/nonexistent-storage');

            $this->artisan('foundation:doctor')
                ->expectsOutput('Not writable: '.$dir.'/nonexistent-storage')
                ->doesntExpectOutput('Foundation checks passed.')
                ->assertFailed();
        } finally {
            app()->useStoragePath($originalStorage);
            @unlink($dir.'/module.json');
            @unlink($dir.'/composer.json');
            @rmdir($dir);
        }
    } finally {
        app()->instance(ModuleRegistry::class, $originalRegistry);
    }
});
