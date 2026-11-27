<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\UsersRoles\Database\Factories\UserFactory;
use Liberu\Foundation\UsersRoles\DatabaseServiceProvider;
use Liberu\Foundation\UsersRoles\Models\User;
use Liberu\Foundation\UsersRoles\Policies\UserPolicy;
use Liberu\Foundation\UsersRoles\UsersRolesServiceProvider;

it('loads migrations via DatabaseServiceProvider and registers UserPolicy via UsersRolesServiceProvider', function () {
    $dbProvider = new DatabaseServiceProvider(app());
    $dbProvider->boot();

    $customUserClass = new class() extends AuthUser {};
    config()->set('auth.providers.users.model', $customUserClass::class);

    $provider = new UsersRolesServiceProvider(app());
    $provider->boot();

    expect(Gate::getPolicyFor($customUserClass::class))->toBeInstanceOf(UserPolicy::class);
});

it('builds user attributes and unverified state with UserFactory', function () {
    config()->set('auth.providers.users.model', User::class);

    $factory = UserFactory::new();

    expect($factory->modelName())->toBe(User::class);

    $verified = $factory->make();
    $unverified = $factory->unverified()->make();

    expect($verified->name)->toBeString()->not->toBeEmpty()
        ->and($verified->email)->toContain('@')
        ->and($verified->email_verified_at)->not->toBeNull()
        ->and($unverified->email_verified_at)->toBeNull();
});

it('delegates all UserPolicy abilities to user permissions', function () {
    $policy = new UserPolicy();

    $allowed = new class() extends AuthUser
    {
        public array $checked = [];

        public function can($abilities, $arguments = []): bool
        {
            $this->checked[] = $abilities;

            return true;
        }
    };

    $methods = [
        'viewAny' => 'ViewAny:User',
        'view' => 'View:User',
        'create' => 'Create:User',
        'update' => 'Update:User',
        'delete' => 'Delete:User',
        'deleteAny' => 'DeleteAny:User',
        'restore' => 'Restore:User',
        'forceDelete' => 'ForceDelete:User',
        'forceDeleteAny' => 'ForceDeleteAny:User',
        'restoreAny' => 'RestoreAny:User',
        'replicate' => 'Replicate:User',
        'reorder' => 'Reorder:User',
    ];

    foreach ($methods as $method => $permission) {
        expect($policy->{$method}($allowed))->toBeTrue();
    }

    expect($allowed->checked)->toBe(array_values($methods));
});

it('checks roles across teams via HasUserRoles and UserRoleLookup', function () {
    config()->set([
        'permission.table_names.roles' => 'coverage_roles',
        'permission.table_names.model_has_roles' => 'coverage_model_has_roles',
    ]);

    Schema::create('coverage_roles', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    Schema::create('coverage_model_has_roles', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
    });

    $roleId = app('db')->table('coverage_roles')->insertGetId(['name' => 'super_admin']);

    $user = (new User())->forceFill(['id' => 99]);

    expect($user->isSuperAdmin())->toBeFalse();

    app('db')->table('coverage_model_has_roles')->insert([
        'role_id' => $roleId,
        'model_type' => User::class,
        'model_id' => 99,
    ]);

    expect($user->hasRoleInAnyTeam('super_admin'))->toBeTrue()
        ->and($user->isSuperAdmin())->toBeTrue();
});
