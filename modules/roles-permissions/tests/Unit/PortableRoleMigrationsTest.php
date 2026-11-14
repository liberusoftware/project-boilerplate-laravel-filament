<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->previousRoleContainer = Container::getInstance();
    $this->previousRoleFacade = Facade::getFacadeApplication();
    $container = new Container();
    Container::setInstance($container);
    $container->instance('config', new Repository([
        'auth' => ['defaults' => ['guard' => 'staff'], 'guards' => ['staff' => ['provider' => 'staff']], 'providers' => ['staff' => ['model' => PortableRoleActor::class]]],
        'permission' => ['teams' => true, 'table_names' => ['roles' => 'roles', 'model_has_roles' => 'model_has_roles'], 'cache' => ['key' => 'role-cache']],
    ]));
    $this->roleDatabase = new Capsule($container);
    $this->roleDatabase->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $container->instance('db', $this->roleDatabase->getDatabaseManager());
    $container->instance('db.schema', $this->roleDatabase->getConnection()->getSchemaBuilder());
    $container->instance('cache', new CacheRepository(new ArrayStore()));
    $this->roleLog = new class()
    {
        public array $entries = [];

        public function info(string $message, array $context): void
        {
            $this->entries[] = $context;
        }
    };
    $container->instance('log', $this->roleLog);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    PortablePermissionCatalog::$calls = 0;
});

afterEach(function () {
    $this->roleDatabase->getConnection()->disconnect();
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->previousRoleFacade);
    Container::setInstance($this->previousRoleContainer);
});

it('does not require role tables when team permissions are disabled', function () {
    config(['permission.teams' => false]);
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_06_000003_backfill_role_assignment_teams.php';
    $migration->up();
    $migration->down();

    expect(Schema::getTableListing())->toBe([]);
});

it('backfills the configured actor morph while preserving global and unrelated assignments', function () {
    Schema::create('roles', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    Schema::create('staff_accounts', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('current_team_id')->nullable();
    });
    Schema::create('model_has_roles', function (Blueprint $table): void {
        $table->unsignedBigInteger('role_id');
        $table->unsignedBigInteger('model_id');
        $table->string('model_type');
        $table->unsignedBigInteger('team_id')->nullable();
    });
    DB::table('roles')->insert([['id' => 1, 'name' => 'super_admin'], ['id' => 2, 'name' => 'editor']]);
    DB::table('staff_accounts')->insert([['id' => 1, 'current_team_id' => 42], ['id' => 2, 'current_team_id' => null]]);
    DB::table('model_has_roles')->insert([
        ['role_id' => 1, 'model_id' => 1, 'model_type' => PortableRoleActor::class, 'team_id' => 99],
        ['role_id' => 2, 'model_id' => 1, 'model_type' => PortableRoleActor::class, 'team_id' => null],
        ['role_id' => 2, 'model_id' => 2, 'model_type' => PortableRoleActor::class, 'team_id' => 99],
        ['role_id' => 2, 'model_id' => 1, 'model_type' => 'unrelated-model', 'team_id' => 77],
    ]);
    app('cache')->put('role-cache', 'stale');
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_06_000003_backfill_role_assignment_teams.php';
    $migration->up();

    $rows = DB::table('model_has_roles')->get();
    expect($rows->pluck('team_id')->all())->toBe([null, 42, null, 77])
        ->and($this->roleLog->entries)->toBe([['model_id' => 2, 'role_id' => 2]])
        ->and(app('cache')->has('role-cache'))->toBeFalse();
});

it('allows hosts without a legacy permission catalog to migrate', function () {
    config(['permission.catalog' => 'UninstalledHostPermissionCatalog']);
    expect(class_exists('UninstalledHostPermissionCatalog'))->toBeFalse();
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_10_000100_seed_role_permissions.php';
    $migration->up();
    $migration->down();

    expect(PortablePermissionCatalog::$calls)->toBe(0);
});

it('delegates catalog seeding to an explicitly configured host boundary', function () {
    config(['permission.catalog' => PortablePermissionCatalog::class]);
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_10_000100_seed_role_permissions.php';
    $migration->up();

    expect(PortablePermissionCatalog::$calls)->toBe(1);
});

class PortableRoleActor extends Model
{
    protected $table = 'staff_accounts';
}

class PortablePermissionCatalog
{
    public static int $calls = 0;

    public static function sync(): void
    {
        self::$calls++;
    }
}
