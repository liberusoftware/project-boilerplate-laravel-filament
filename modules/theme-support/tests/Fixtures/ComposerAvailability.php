<?php

namespace Liberu\Foundation\Theme\Tests\Fixtures {
    final class ComposerAvailability
    {
        public static bool $available = true;
    }
}

namespace Liberu\Foundation\Theme\Discovery {
    use Composer\InstalledVersions;
    use Liberu\Foundation\Theme\Tests\Fixtures\ComposerAvailability;

    function class_exists(string $class, bool $autoload = true): bool
    {
        return $class === InstalledVersions::class && ! ComposerAvailability::$available
            ? false
            : \class_exists($class, $autoload);
    }
}
