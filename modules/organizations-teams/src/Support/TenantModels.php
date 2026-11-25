<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Support;

use Illuminate\Container\Container;
use Liberu\Foundation\Organizations\Traits\IsTenantModel;
use ReflectionClass;

/**
 * Single source of truth for "which Eloquent models are team-scoped."
 *
 * Discovers every instantiable tenant model in the module-owned model paths
 * that uses IsTenantModel.
 * Uses the running host when available, with a package-relative fallback at
 * PHPUnit collection time, before the app boots. CrossTenantLeakageTest and
 * TeamBackupService both consume this — so the test that proves no model
 * escapes the tenant scope also proves the backup covers every model.
 */
class TenantModels
{
    /** @var list<class-string>|null */
    private static ?array $override = null;

    /**
     * Override discovered tenant models (primarily for testing or custom host registries).
     * Pass null to restore automatic discovery.
     *
     * @param  list<class-string>|null  $models
     */
    public static function useModels(?array $models): void
    {
        self::$override = $models;
    }

    /**
     * @return list<class-string>
     */
    public static function all(): array
    {
        if (self::$override !== null) {
            return self::$override;
        }

        $container = Container::getInstance();
        $hostRoot = $container->bound('path.base') ? $container->make('path.base') : null;
        $projectRoot = is_string($hostRoot) && $hostRoot !== '' ? $hostRoot : dirname(__DIR__, 5);
        $files = glob($projectRoot.'/app/Models/*.php') ?: [];

        foreach (glob($projectRoot.'/modules/*/src/Models/*.php') ?: [] as $file) {
            $files[] = $file;
        }

        $models = [];

        foreach ($files as $file) {
            $class = self::classFromFile($file);

            if ($class === null || ! class_exists($class)) {
                continue;
            }
            if (! in_array(IsTenantModel::class, class_uses_recursive($class), true)) {
                continue;
            }
            if (! (new ReflectionClass($class))->isInstantiable()) {
                continue;
            }

            $models[class_basename($class)] = $class;
        }

        ksort($models);

        /** @var list<class-string> */
        return array_values($models);
    }

    private static function classFromFile(string $file): ?string
    {
        $contents = @file_get_contents($file);

        if ($contents !== false && preg_match('/^\s*namespace\s+([^;\s]+)\s*;/m', $contents, $matches) === 1) {
            return $matches[1].'\\'.basename($file, '.php');
        }

        return 'App\\Models\\'.basename($file, '.php');
    }
}
