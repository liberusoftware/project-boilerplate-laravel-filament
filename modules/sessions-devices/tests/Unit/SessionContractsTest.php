<?php

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\Sessions\Queries\SessionReader;
use Liberu\Foundation\Sessions\SessionsDevicesServiceProvider;

beforeEach(function () {
    $this->previousSessionContainer = Container::getInstance();
    $this->previousSessionFacade = Facade::getFacadeApplication();
    $this->sessionApplication = new Application(sys_get_temp_dir());
    $this->sessionApplication->instance('config', new Repository());
    $this->sessionDatabase = new Capsule($this->sessionApplication);
    $this->sessionDatabase->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $this->sessionApplication->instance('db', $this->sessionDatabase->getDatabaseManager());
    $this->sessionApplication->instance('db.schema', $this->sessionDatabase->getConnection()->getSchemaBuilder());
    $this->sessionMigrator = new class()
    {
        public array $paths = [];

        public function path(string $path): void
        {
            $this->paths[] = $path;
        }
    };
    $this->sessionApplication->instance('migrator', $this->sessionMigrator);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->sessionApplication);
    $provider = new SessionsDevicesServiceProvider($this->sessionApplication);
    $provider->register();
    $provider->boot();
});

afterEach(function () {
    $this->sessionDatabase->getConnection()->disconnect();
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->previousSessionFacade);
    Container::setInstance($this->previousSessionContainer);
});

function sessionContractMigration(): Migration
{
    return require dirname(__DIR__, 2).'/database/migrations/0001_01_01_000003_create_sessions_table.php';
}

function sessionContractRows(): void
{
    sessionContractMigration()->up();
    DB::table('sessions')->insert([
        ['id' => 'current', 'user_id' => 1, 'ip_address' => '203.0.113.42', 'user_agent' => 'Browser A', 'payload' => 'one', 'last_activity' => 4],
        ['id' => 'ipv6', 'user_id' => 1, 'ip_address' => '2001:db8::42', 'user_agent' => 'Browser B', 'payload' => 'two', 'last_activity' => 3],
        ['id' => 'unknown-ip', 'user_id' => 1, 'ip_address' => null, 'user_agent' => null, 'payload' => 'three', 'last_activity' => 2],
        ['id' => 'other-actor', 'user_id' => 2, 'ip_address' => '198.51.100.9', 'user_agent' => 'Browser C', 'payload' => 'four', 'last_activity' => 5],
    ]);
}

it('registers the session configuration and migration path and creates the schema for a minimal host', function () {
    sessionContractMigration()->up();

    expect(config('sessions-devices.table'))->toBe('sessions')
        ->and($this->sessionMigrator->paths)->toBe([dirname(__DIR__, 2).'/src/../database/migrations'])
        ->and(Schema::hasColumns('sessions', ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity']))->toBeTrue();
});

it('preserves an existing host session schema and its data on migration and rollback', function () {
    Schema::create('sessions', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->text('payload');
        $table->string('host_extension');
    });
    DB::table('sessions')->insert(['id' => 'host', 'payload' => 'active', 'host_extension' => 'retained']);
    $migration = sessionContractMigration();
    $migration->up();
    $migration->down();

    expect(Schema::getColumnListing('sessions'))->toBe(['id', 'payload', 'host_extension'])
        ->and(DB::table('sessions')->first()->payload)->toBe('active');
});

it('keeps sessions created by the adapter available when the adapter is rolled back', function () {
    sessionContractRows();
    sessionContractMigration()->down();

    expect(DB::table('sessions')->count())->toBe(4);
});

it('lists only actor-owned sessions in activity order with private addresses and current-session markers', function () {
    sessionContractRows();
    $sessions = (new SessionReader())->forActor(1, 'current');

    expect($sessions->pluck('id')->all())->toBe(['current', 'ipv6', 'unknown-ip'])
        ->and($sessions->pluck('ip_address')->all())->toBe(['203.0.113.0', '2001:db8::0', null])
        ->and($sessions->pluck('is_current')->all())->toBe([true, false, false])
        ->and((new SessionReader())->forActor(3))->toBeEmpty();
});

it('revokes only owned noncurrent sessions and reports missing or repeated revocations', function () {
    sessionContractRows();
    $reader = new SessionReader();

    expect($reader->revoke(1, 'current', 'current'))->toBeFalse()
        ->and($reader->revoke(1, 'other-actor', 'current'))->toBeFalse()
        ->and($reader->revoke(1, 'missing', 'current'))->toBeFalse()
        ->and($reader->revoke(1, 'ipv6', 'current'))->toBeTrue()
        ->and($reader->revoke(1, 'ipv6', 'current'))->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'other-actor')->exists())->toBeTrue();
});

it('honors configured session tables when listing and revoking other sessions', function () {
    sessionContractRows();
    Schema::rename('sessions', 'host_sessions');
    config(['sessions-devices.table' => 'host_sessions']);
    $reader = new SessionReader();

    expect($reader->forActor(1, 'current'))->toHaveCount(3)
        ->and($reader->revokeOthers(1, 'current'))->toBe(2)
        ->and($reader->revokeOthers(1, 'current'))->toBe(0)
        ->and(DB::table('host_sessions')->pluck('id')->sort()->values()->all())->toBe(['current', 'other-actor']);
});
