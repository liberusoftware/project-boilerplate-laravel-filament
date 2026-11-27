<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Auth\User as AuthUser;
use Liberu\Foundation\UsersRoles\Database\Factories\UserFactory;
use Liberu\Foundation\UsersRoles\DatabaseServiceProvider;
use Liberu\Foundation\UsersRoles\Policies\UserPolicy;
use Liberu\Foundation\UsersRoles\Services\UserRoleLookup;
use Liberu\Foundation\UsersRoles\UsersRolesServiceProvider;

it('covers DatabaseServiceProvider, UsersRolesServiceProvider, UserFactory, HasUserRoles, UserRoleLookup, and UserPolicy', function (): void {
    $dbProvider = $this->app->register(DatabaseServiceProvider::class, force: true);
    $dbProvider->boot();

    $mainProvider = $this->app->register(UsersRolesServiceProvider::class, force: true);
    $mainProvider->boot();

    // UserFactory modelName, definition, and unverified state
    $factory = UserFactory::new();
    expect($factory->modelName())->toBeString();
    $verifiedAttrs = $factory->raw();
    $unverifiedAttrs = $factory->unverified()->raw();
    expect($verifiedAttrs['email_verified_at'])->not->toBeNull()
        ->and($unverifiedAttrs['email_verified_at'])->toBeNull();

    // HasUserRoles + UserRoleLookup edge cases
    $user = User::factory()->create();
    expect($user->authorizationIdentifier())->toBe($user->getKey())
        ->and($user->authorizationType())->toBe($user->getMorphClass())
        ->and($user->isSuperAdmin())->toBeFalse();

    $lookup = new UserRoleLookup();
    $unsavedUser = new User();
    expect($lookup->hasRoleInAnyTeam($unsavedUser, 'admin'))->toBeFalse()
        ->and($lookup->hasRoleInAnyTeam($user, []))->toBeFalse()
        ->and($lookup->hasRoleInAnyTeam($user, 'nonexistent_role'))->toBeFalse();

    // UserPolicy delegates all 12 abilities to $authUser->can(...)
    $policy = new UserPolicy();
    $grantingUser = new class() extends AuthUser
    {
        public function can($abilities, $arguments = []): bool
        {
            return true;
        }
    };
    $denyingUser = new class() extends AuthUser
    {
        public function can($abilities, $arguments = []): bool
        {
            return false;
        }
    };

    $methods = [
        'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny',
        'restore', 'forceDelete', 'forceDeleteAny', 'restoreAny', 'replicate', 'reorder',
    ];

    foreach ($methods as $method) {
        expect($policy->{$method}($grantingUser))->toBeTrue()
            ->and($policy->{$method}($denyingUser))->toBeFalse();
    }
});
