<?php

use App\Models\DiscoveryInherited;
use App\Models\DiscoveryScopedAlpha;
use App\Models\DiscoveryScopedZeta;
use App\Support\TenantModels;
use App\Traits\IsTenantModel;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;

require_once dirname(__DIR__, 2).'/src/Legacy/Support/TenantModels.php';

beforeEach(function () {
    $this->originalDiscoveryContainer = Container::getInstance();
    $this->discoveryContainer = new Container();
    Container::setInstance($this->discoveryContainer);
    $this->discoveryRoot = sys_get_temp_dir().'/tenant-discovery-'.bin2hex(random_bytes(6));
    mkdir($this->discoveryRoot.'/app/Models', 0700, true);
    mkdir($this->discoveryRoot.'/modules/example/src/Legacy/Models', 0700, true);

    if (! trait_exists(IsTenantModel::class)) {
        file_put_contents($this->discoveryRoot.'/TenantMarker.php', '<?php namespace App\\Traits; trait IsTenantModel { public function discoveryFixtureMarker(): bool { return true; } }');
        require $this->discoveryRoot.'/TenantMarker.php';
    }

    if (! class_exists('App\\Models\\DiscoveryScopedAlpha')) {
        file_put_contents($this->discoveryRoot.'/FixtureModels.php', <<<'FIXTURES'
<?php
namespace App\Models;
class DiscoveryScopedAlpha extends \Illuminate\Database\Eloquent\Model { use \App\Traits\IsTenantModel; }
class DiscoveryScopedZeta extends \Illuminate\Database\Eloquent\Model { use \App\Traits\IsTenantModel; }
class DiscoveryUnscoped extends \Illuminate\Database\Eloquent\Model {}
abstract class DiscoveryAbstract extends \Illuminate\Database\Eloquent\Model { use \App\Traits\IsTenantModel; }
class DiscoveryInherited extends DiscoveryAbstract {}
FIXTURES);
        require $this->discoveryRoot.'/FixtureModels.php';
    }

    foreach (['DiscoveryScopedAlpha', 'DiscoveryUnscoped', 'DiscoveryAbstract', 'DiscoveryMissing', 'DiscoveryInherited'] as $name) {
        file_put_contents($this->discoveryRoot.'/app/Models/'.$name.'.php', '<?php');
    }

    foreach (['DiscoveryScopedZeta', 'DiscoveryScopedAlpha'] as $name) {
        file_put_contents($this->discoveryRoot.'/modules/example/src/Legacy/Models/'.$name.'.php', '<?php');
    }
});

afterEach(function () {
    Container::setInstance($this->originalDiscoveryContainer);
    (new Filesystem())->deleteDirectory($this->discoveryRoot);
});

it('discovers sorted unique instantiable tenant models from the running host', function () {
    $this->discoveryContainer->instance('path.base', $this->discoveryRoot);

    expect(TenantModels::all())->toBe([
        DiscoveryInherited::class,
        DiscoveryScopedAlpha::class,
        DiscoveryScopedZeta::class,
    ]);
});

it('returns no models for a host without model files', function () {
    $empty = $this->discoveryRoot.'/empty';
    mkdir($empty);
    $this->discoveryContainer->instance('path.base', $empty);

    expect(TenantModels::all())->toBe([]);
});

it('supports collection before an application has bound its base path', function () {
    expect(TenantModels::all())->toBeArray()
        ->not->toContain(DiscoveryScopedAlpha::class);
});

it('falls back to package-relative discovery for an invalid host path binding', function () {
    $this->discoveryContainer->instance('path.base', ['invalid']);

    expect(TenantModels::all())->toBeArray()
        ->not->toContain(DiscoveryScopedAlpha::class);
});
