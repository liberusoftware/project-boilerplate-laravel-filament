<?php

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->previousSchemaApplication = Facade::getFacadeApplication();
    $this->migrationContainer = new Container();
    $this->migrationDatabase = new Capsule($this->migrationContainer);
    $this->migrationDatabase->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $this->migrationContainer->instance('db', $this->migrationDatabase->getDatabaseManager());
    $this->migrationContainer->instance('db.schema', $this->migrationDatabase->getConnection()->getSchemaBuilder());
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->migrationContainer);
});

afterEach(function () {
    $this->migrationDatabase->getConnection()->disconnect();
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->previousSchemaApplication);
});

function optionalResourceMigration(string $name): Migration
{
    $directory = dirname(__DIR__, 2).'/database/migrations/';
    if ($name === 'resources') {
        return require $directory.'2024_09_29_093707_add_team_to_resources.php';
    }

    if ($name === 'tickets') {
        return require $directory.'2026_07_07_120000_add_team_id_to_tickets_table.php';
    }

    require_once $directory.'2024_11_17_000000_add_team_id_to-staff_resource_tables.php';

    return new AddTeamIdToStaffResourceTables();
}

it('leaves absent optional resource tables absent during migration and rollback', function (string $name) {
    $migration = optionalResourceMigration($name);
    $migration->up();
    $migration->down();

    expect(Schema::getTableListing())->toBe([]);
})->with(['resources', 'staff', 'tickets']);

it('adds ownership to installed resource tables only and can be run again', function (string $name) {
    Schema::create('teams', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('contacts', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    $migration = optionalResourceMigration($name);
    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('contacts', 'team_id'))->toBeTrue()
        ->and(Schema::hasTable('opportunities'))->toBeFalse()
        ->and(Schema::getColumnListing('contacts'))->toBe(['id', 'name', 'team_id']);
})->with(['resources', 'staff']);

it('preserves existing ownership columns', function (string $name) {
    Schema::create('contacts', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('team_id')->nullable();
    });
    optionalResourceMigration($name)->up();

    expect(Schema::getForeignKeys('contacts'))->toBe([])
        ->and(Schema::getColumnListing('contacts'))->toBe(['id', 'team_id']);
})->with(['resources', 'staff']);

it('backfills installed tickets without requiring optional resource tables', function () {
    Schema::create('teams', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('current_team_id')->nullable();
    });
    Schema::create('tickets', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
    });
    DB::table('teams')->insert(['id' => 42]);
    DB::table('users')->insert(['id' => 1, 'current_team_id' => 42]);
    DB::table('tickets')->insert(['id' => 1, 'user_id' => 1]);
    $migration = optionalResourceMigration('tickets');
    $migration->up();
    $migration->up();

    expect(DB::table('tickets')->value('team_id'))->toBe(42);
});
