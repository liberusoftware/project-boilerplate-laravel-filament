<?php

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? [];

if (! is_array($arguments) || count($arguments) < 3) {
    fwrite(STDERR, "Usage: php tests/run-module-host.php <host-project> <module-directory> [Pest options]\n");
    exit(2);
}

$host = realpath($arguments[1]);
$module = realpath($arguments[2]);
$temporary = sys_get_temp_dir().'/liberu-host-contracts-'.bin2hex(random_bytes(8));

try {
    if ($host === false || $module === false || ! is_file($host.'/phpunit.xml') || ! is_file($host.'/vendor/autoload.php') || ! is_file($module.'/composer.json') || ! is_dir($module.'/tests/Feature')) {
        throw new RuntimeException('Provide an installed host project and a module with host feature contracts.');
    }

    $composer = json_decode(file_get_contents($module.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    if (! empty($composer['autoload']['files'])) {
        throw new RuntimeException('Modules with autoloaded functions require their isolated native suite.');
    }

    $mappings = [];

    if (is_dir($module.'/src/Legacy')) {
        $mappings['App\\'] = [$module.'/src/Legacy'];
    }

    foreach ($composer['autoload']['psr-4'] ?? [] as $namespace => $paths) {
        foreach ((array) $paths as $path) {
            $mappings[$namespace][] = $module.'/'.$path;
        }
    }

    if (! mkdir($temporary, 0700)) {
        throw new RuntimeException('Cannot create the isolated host contract configuration.');
    }

    foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/private', 'app/public'] as $directory) {
        if (! mkdir($temporary.'/storage/'.$directory, 0700, true) && ! is_dir($temporary.'/storage/'.$directory)) {
            throw new RuntimeException('Cannot create isolated host contract storage.');
        }
    }

    $bootstrap = "<?php\nrequire_once ".var_export($host.'/vendor/autoload.php', true).";\n"
        .'$loader = new \\Composer\\Autoload\\ClassLoader();'."\n"
        .'foreach ('.var_export($mappings, true).' as $namespace => $paths) { $loader->addPsr4($namespace, $paths); }'."\n"
        .'$loader->register(true);'."\n";

    if (is_file($host.'/tests/bootstrap.php')) {
        $bootstrap .= 'require '.var_export($host.'/tests/bootstrap.php', true).";\n";
    }

    if (file_put_contents($temporary.'/bootstrap.php', $bootstrap) === false) {
        throw new RuntimeException('Cannot write the host contract bootstrap.');
    }

    $configuration = new DOMDocument();

    if (! $configuration->load($host.'/phpunit.xml')) {
        throw new RuntimeException('Cannot read the host PHPUnit configuration.');
    }

    $configuration->documentElement->setAttribute('bootstrap', $temporary.'/bootstrap.php');

    foreach (['testsuites', 'source'] as $name) {
        foreach (iterator_to_array($configuration->getElementsByTagName($name)) as $node) {
            $node->parentNode->removeChild($node);
        }
    }

    $suites = $configuration->documentElement->appendChild($configuration->createElement('testsuites'));
    $suite = $suites->appendChild($configuration->createElement('testsuite'));
    $suite->setAttribute('name', 'Module host contracts');
    $directory = $configuration->createElement('directory');
    $directory->appendChild($configuration->createTextNode($module.'/tests/Feature'));
    $suite->appendChild($directory);
    $include = $configuration->documentElement->appendChild($configuration->createElement('source'))->appendChild($configuration->createElement('include'));
    $directory = $configuration->createElement('directory');
    $directory->appendChild($configuration->createTextNode($module.'/src'));
    $include->appendChild($directory);
    $php = $configuration->getElementsByTagName('php')->item(0) ?? $configuration->documentElement->appendChild($configuration->createElement('php'));

    foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'LARAVEL_STORAGE_PATH' => $temporary.'/storage'] as $name => $value) {
        foreach (iterator_to_array($php->getElementsByTagName('env')) as $environment) {
            if ($environment->getAttribute('name') === $name) {
                $environment->parentNode->removeChild($environment);
            }
        }

        $environment = $php->appendChild($configuration->createElement('env'));
        $environment->setAttribute('name', $name);
        $environment->setAttribute('value', $value);
        $environment->setAttribute('force', 'true');
    }

    if ($configuration->save($temporary.'/phpunit.xml') === false) {
        throw new RuntimeException('Cannot write the host contract configuration.');
    }

    $command = [PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'opcache.enable_cli=0', '-d', 'pcov.directory='.$module.'/src'];
    $pcovExtension = getenv('LIBERU_PCOV_EXTENSION');

    if ($pcovExtension !== false && $pcovExtension !== '') {
        if (! is_file($pcovExtension)) {
            throw new RuntimeException('LIBERU_PCOV_EXTENSION must identify an installed PCOV extension.');
        }

        array_push($command, '-d', 'extension='.$pcovExtension);
    }

    array_push($command, $host.'/vendor/pestphp/pest/bin/pest', '--configuration='.$temporary.'/phpunit.xml', ...array_slice($arguments, 3));
    fwrite(STDOUT, "Host fixture: {$host}\nActual module source: {$module}/src\n");
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $host);

    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start the module host contracts.');
    }

    $status = proc_close($process);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    $status = 2;
} finally {
    if (is_dir($temporary)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($temporary);
    }
}

exit($status);
