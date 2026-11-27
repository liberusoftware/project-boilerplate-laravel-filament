<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\Jetstream;
use Liberu\Foundation\Organizations\OrganizationsServiceProvider;
use Liberu\Foundation\Organizations\Tests\Fixtures\OrgTestUser;
use Liberu\Foundation\RolesPermissions\RolesPermissionsServiceProvider;
use Liberu\Foundation\Tenancy\TenancyServiceProvider;
use Liberu\PackageTestbench\PackageTestCase;
use Liberu\PackageTestbench\UsesTestUser;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;

abstract class TestCase extends PackageTestCase
{
    use UsesTestUser;

    private string $previousJetstreamUserModel = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousJetstreamUserModel = Jetstream::userModel();
        Jetstream::useUserModel(OrgTestUser::class);

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'current_team_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedBigInteger('current_team_id')->nullable();
            });
        }

        if (! Schema::hasTable('organizations')) {
            Schema::create('organizations', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->unsignedBigInteger('owner_id')->nullable();
                $table->string('status')->default('active');
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('territories')) {
            Schema::create('territories', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('team_id');
                $table->string('name');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('territory_user')) {
            Schema::create('territory_user', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('territory_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        if ($this->previousJetstreamUserModel !== '') {
            Jetstream::useUserModel($this->previousJetstreamUserModel);
        }

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', OrgTestUser::class);
        $app['config']->set('auth.guards.sanctum', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('activitylog.enabled', false);
        $app['config']->set('permission.teams', true);
        $app['config']->set('permission.column_names.team_foreign_key', 'team_id');
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_values(array_unique([
            PermissionServiceProvider::class,
            RolesPermissionsServiceProvider::class,
            TenancyServiceProvider::class,
            OrganizationsServiceProvider::class,
            ...parent::getPackageProviders($app),
        ]));
    }
}
