<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\TwoFactor\Enforcement\TwoFactorPolicy;
use Liberu\Foundation\TwoFactor\Recovery\RecoveryCodeHasher;
use Liberu\Foundation\TwoFactor\TrustedDevices\TrustedDeviceManager;
use Liberu\Foundation\TwoFactor\TwoFactorServiceProvider;

beforeEach(function () {
    $this->previousTwoFactorContainer = Container::getInstance();
    $this->previousTwoFactorFacade = Facade::getFacadeApplication();
    $this->twoFactorApplication = new Application(sys_get_temp_dir());
    $this->twoFactorApplication->instance('config', new Repository());
    $this->twoFactorDatabase = new Capsule($this->twoFactorApplication);
    $this->twoFactorDatabase->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $this->twoFactorApplication->instance('db', $this->twoFactorDatabase->getDatabaseManager());
    $this->twoFactorApplication->instance('db.schema', $this->twoFactorDatabase->getConnection()->getSchemaBuilder());
    $this->twoFactorMigrator = new class()
    {
        public array $paths = [];

        public function path(string $path): void
        {
            $this->paths[] = $path;
        }
    };
    $this->twoFactorApplication->instance('migrator', $this->twoFactorMigrator);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->twoFactorApplication);
    $this->previousTwoFactorClock = Carbon::getTestNow();
    Carbon::setTestNow('2026-11-14 12:00:00');
    $provider = new TwoFactorServiceProvider($this->twoFactorApplication);
    $provider->register();
    $provider->boot();
});

afterEach(function () {
    Carbon::setTestNow($this->previousTwoFactorClock);
    $this->twoFactorDatabase->getConnection()->disconnect();
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->previousTwoFactorFacade);
    Container::setInstance($this->previousTwoFactorContainer);
});

function twoFactorColumnMigration(): Migration
{
    return require dirname(__DIR__, 2).'/database/migrations/2026_06_29_123952_add_two_factor_columns_to_users_table.php';
}

function twoFactorDeviceMigration(): Migration
{
    return require dirname(__DIR__, 2).'/database/migrations/2026_08_01_060000_create_two_factor_trusted_devices_table.php';
}

it('adds factor columns for a minimal identity schema and registers its configuration and migrations', function () {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('password');
    });
    twoFactorColumnMigration()->up();

    expect(Schema::hasColumns('users', ['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']))->toBeTrue()
        ->and(config('two-factor.required_roles'))->toBe(['admin', 'super_admin'])
        ->and($this->twoFactorMigrator->paths)->toBe([dirname(__DIR__, 2).'/src/../database/migrations']);
});

it('preserves partially installed factors when adding missing columns or rolling back the adapter', function () {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('password');
        $table->text('two_factor_secret')->nullable();
    });
    DB::table('users')->insert(['id' => 1, 'password' => 'hash', 'two_factor_secret' => 'enrolled']);
    $migration = twoFactorColumnMigration();
    $migration->up();
    $migration->up();
    $migration->down();

    expect(Schema::hasColumns('users', ['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']))->toBeTrue()
        ->and(DB::table('users')->value('two_factor_secret'))->toBe('enrolled');
});

it('requires factors only for configured roles unless every actor must enroll', function () {
    $actor = new GenericUser(['id' => 1]);
    $policy = new TwoFactorPolicy();

    expect($policy->requiredFor($actor))->toBeFalse()
        ->and($policy->requiredFor($actor, ['customer']))->toBeFalse()
        ->and($policy->requiredFor($actor, ['customer', 'admin']))->toBeTrue();
    config(['two-factor.enforce_all' => true]);

    expect($policy->requiredFor($actor))->toBeTrue();
});

it('hashes recovery codes and consumes each matching code only once', function () {
    $hasher = new BcryptHasher(['rounds' => 4]);
    $recovery = new RecoveryCodeHasher($hasher);
    $hashes = $recovery->hash(['first-recovery', 'second-recovery']);
    $initial = $hashes;

    expect($hashes)->not->toContain('first-recovery', 'second-recovery')
        ->and($hasher->check('first-recovery', $hashes[0]))->toBeTrue()
        ->and($recovery->verifyAndConsume('incorrect', $hashes))->toBeFalse()
        ->and($hashes)->toBe($initial)
        ->and($recovery->verifyAndConsume('second-recovery', $hashes))->toBeTrue()
        ->and($hashes)->toBe([$initial[0]])
        ->and($recovery->verifyAndConsume('second-recovery', $hashes))->toBeFalse()
        ->and($recovery->verifyAndConsume('first-recovery', $hashes))->toBeTrue()
        ->and($hashes)->toBe([])
        ->and($recovery->verifyAndConsume('first-recovery', $hashes))->toBeFalse();
});

it('stores only hashed device secrets and rejects other actors tampering expiry and revocation', function () {
    twoFactorDeviceMigration()->up();
    config(['two-factor.trusted_device_days' => 2]);
    $manager = new TrustedDeviceManager();
    $credential = $manager->issue(7, 'Laptop');
    [$selector, $secret] = explode('.', $credential, 2);
    $device = DB::table('two_factor_trusted_devices')->first();

    expect($device->selector)->toBe($selector)
        ->and($device->secret_hash)->toBe(hash('sha256', $secret))
        ->and($device->secret_hash)->not->toBe($secret)
        ->and($device->expires_at)->toBe('2026-11-16 12:00:00')
        ->and($manager->valid(8, $credential))->toBeFalse()
        ->and($manager->valid(7, $selector.'.tampered'))->toBeFalse()
        ->and($manager->valid(7, 'malformed'))->toBeFalse();
    Carbon::setTestNow('2026-11-14 13:00:00');

    expect($manager->valid(7, $credential))->toBeTrue()
        ->and(DB::table('two_factor_trusted_devices')->value('last_used_at'))->toBe('2026-11-14 13:00:00');
    $other = $manager->issue(8);

    expect($manager->revokeAll(7))->toBe(1)
        ->and($manager->revokeAll(7))->toBe(0)
        ->and($manager->valid(7, $credential))->toBeFalse()
        ->and($manager->valid(8, $other))->toBeTrue();
    Carbon::setTestNow('2026-11-16 13:00:01');

    expect($manager->valid(8, $other))->toBeFalse();
    twoFactorDeviceMigration()->down();

    expect(Schema::hasTable('two_factor_trusted_devices'))->toBeFalse();
});
