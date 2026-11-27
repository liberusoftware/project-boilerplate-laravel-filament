<?php

namespace Liberu\Foundation\OrganizationsFilament\Tests;

use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\Jetstream;
use Liberu\Foundation\Organizations\OrganizationsServiceProvider;
use Liberu\Foundation\OrganizationsFilament\Tests\Fixtures\OrganizationUser;
use Liberu\Foundation\OrganizationsFilament\Tests\Fixtures\TestPanelProvider;
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

        Filament::setCurrentPanel('admin');

        $this->previousJetstreamUserModel = Jetstream::userModel();
        Jetstream::useUserModel(OrganizationUser::class);

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'current_team_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedBigInteger('current_team_id')->nullable();
            });
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->string('event')->nullable();
                $table->string('action')->nullable();
                $table->text('description')->nullable();
                $table->string('auditable_type')->nullable();
                $table->unsignedBigInteger('auditable_id')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('ip_address')->nullable();
                $table->string('user_agent')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
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

        $app['config']->set('auth.providers.users.model', OrganizationUser::class);
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
            ...$this->discoveredProviders(),
            PermissionServiceProvider::class,
            RolesPermissionsServiceProvider::class,
            TenancyServiceProvider::class,
            OrganizationsServiceProvider::class,
            ...parent::getPackageProviders($app),
            TestPanelProvider::class,
        ]));
    }

    /**
     * @return array<int, class-string>
     */
    private function discoveredProviders(): array
    {
        $installed = json_decode(
            (string) file_get_contents($this->packageRoot().'/vendor/composer/installed.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $providers = [];

        foreach ($installed['packages'] ?? [] as $package) {
            foreach ((array) ($package['extra']['laravel']['providers'] ?? []) as $provider) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }
}
