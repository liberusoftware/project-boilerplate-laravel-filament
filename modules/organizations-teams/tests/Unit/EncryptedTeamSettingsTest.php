<?php

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\Organizations\Models\Team;

beforeEach(function () {
    $this->previousEncrypter = Model::currentEncrypter();
    Model::encryptUsing(new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
});

afterEach(function () {
    Model::encryptUsing($this->previousEncrypter);
});

it('encrypts workspace credentials and excludes them from serialization and mass assignment', function () {
    $team = new Team;
    $team->forceFill(['name' => 'Workspace', 'settings' => ['api_key' => 'private-key']]);
    $raw = $team->getAttributes()['settings'];

    expect($raw)->not->toContain('private-key')
        ->and($team->toArray())->not->toHaveKey('settings')
        ->and($team->toJson())->not->toContain('private-key');
    $team->fill(['settings' => ['api_key' => 'untrusted-replacement']]);
    expect($team->settings['api_key'])->toBe('private-key');

    $loaded = new Team;
    $loaded->setRawAttributes(['settings' => $raw]);
    expect($loaded->settings)->toBe(['api_key' => 'private-key']);
});

it('adds portable encrypted settings only when teams exist and preserves them on rollback', function () {
    $previous = Facade::getFacadeApplication();
    $container = new Container;
    $database = new Capsule($container);
    $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $container->instance('db', $database->getDatabaseManager());
    $container->instance('db.schema', $database->getConnection()->getSchemaBuilder());
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    try {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_14_000000_add_encrypted_settings_to_teams.php';
        $migration->up();
        expect(Schema::hasTable('teams'))->toBeFalse();
        Schema::create('teams', function ($table) {
            $table->id();
        });
        $migration->up();
        $migration->up();
        expect(Schema::getColumnType('teams', 'settings'))->toBe('text');
        $migration->down();
        expect(Schema::hasColumn('teams', 'settings'))->toBeTrue();
    } finally {
        $database->getConnection()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($previous);
    }
});
