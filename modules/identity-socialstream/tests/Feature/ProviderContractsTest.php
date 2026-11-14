<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use JoelButcher\Socialstream\Contracts\CreatesConnectedAccounts;
use JoelButcher\Socialstream\Features;
use JoelButcher\Socialstream\RefreshedCredentials;
use JoelButcher\Socialstream\Socialstream;
use Laravel\Jetstream\Features as JetstreamFeatures;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\One\User as OAuthOneUser;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as OAuthTwoUser;
use Liberu\Foundation\Identity\Socialstream\Actions\CreateConnectedAccount;
use Liberu\Foundation\Identity\Socialstream\Actions\CreateUserFromProvider;
use Liberu\Foundation\Identity\Socialstream\Actions\GenerateRedirectForProvider;
use Liberu\Foundation\Identity\Socialstream\Actions\HandleInvalidState;
use Liberu\Foundation\Identity\Socialstream\Actions\ResolveSocialiteUser;
use Liberu\Foundation\Identity\Socialstream\Actions\SetUserPassword;
use Liberu\Foundation\Identity\Socialstream\Actions\UpdateConnectedAccount;
use Liberu\Foundation\Identity\Socialstream\Models\ConnectedAccount;
use Liberu\Foundation\Identity\Socialstream\Policies\ConnectedAccountPolicy;
use Liberu\Foundation\Identity\Socialstream\Tests\SocialstreamActor;
use Liberu\Foundation\Organizations\Models\Team;
use Symfony\Component\HttpFoundation\RedirectResponse;

beforeEach(function () {
    $this->travelTo(now()->startOfSecond());
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('profile_photo_path')->nullable();
        $table->timestamps();
    });
    Schema::create('teams', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('name');
        $table->boolean('personal_team');
        $table->timestamps();
    });
});

function socialstreamAccountMigration(): Migration
{
    return require dirname(__DIR__, 2).'/database/migrations/2026_06_29_123955_create_connected_accounts_table.php';
}

it('preserves existing linked identities and custom schema during migration and rollback', function () {
    Schema::create('connected_accounts', function (Blueprint $table): void {
        $table->id();
        $table->string('host_field');
    });
    DB::table('connected_accounts')->insert(['id' => 1, 'host_field' => 'retained']);
    $migration = socialstreamAccountMigration();
    $migration->up();
    $migration->down();

    expect(Schema::getColumnListing('connected_accounts'))->toBe(['id', 'host_field'])
        ->and(DB::table('connected_accounts')->value('host_field'))->toBe('retained');
});

it('creates linked accounts for Eloquent actors using both supported credential shapes', function (bool $oauthTwo) {
    socialstreamAccountMigration()->up();
    $actor = SocialstreamActor::create(['name' => 'Account Owner', 'email' => 'owner@example.test']);
    $provider = $oauthTwo ? OAuthTwoUser::fake() : OAuthOneUser::fake();
    $account = (new CreateConnectedAccount())->create($actor, 'GITHUB', $provider);

    expect($account)->toBeInstanceOf(ConnectedAccount::class)
        ->and((int) $account->user_id)->toBe($actor->id)
        ->and($account->provider)->toBe('github')
        ->and($account->provider_id)->toBe((string) $provider->getId())
        ->and($account->expires_at?->equalTo(now()->addHour()))->toBe($oauthTwo ? true : null)
        ->and($account->toArray())->not->toHaveKeys(['token', 'secret', 'refresh_token'])
        ->and(ConnectedAccount::factory()->make(['user_id' => $actor->id]))->toBeInstanceOf(ConnectedAccount::class);
})->with([true, false]);

it('rejects nonmodel actors before storing linked credentials or passwords', function () {
    expect(fn () => (new CreateConnectedAccount())->create(new stdClass(), 'github', OAuthTwoUser::fake()))
        ->toThrow(InvalidArgumentException::class, 'Eloquent user model')
        ->and(fn () => (new SetUserPassword())->set(new stdClass(), []))
        ->toThrow(InvalidArgumentException::class, 'Eloquent user models');
});

it('validates password confirmation and persists a real hash for a valid password', function () {
    config(['hashing.bcrypt.rounds' => 4]);
    $actor = SocialstreamActor::create(['name' => 'Password Owner', 'email' => 'password@example.test']);
    $action = new SetUserPassword();
    expect(fn () => $action->set($actor, ['password' => 'valid-password', 'password_confirmation' => 'different']))
        ->toThrow(ValidationException::class);
    $action->set($actor, ['password' => 'valid-password', 'password_confirmation' => 'valid-password']);

    expect(Hash::check('valid-password', $actor->fresh()->password))->toBeTrue();
});

it('authorizes ownership before changing account data and keeps all policy operations scoped', function () {
    socialstreamAccountMigration()->up();
    $owner = SocialstreamActor::create(['name' => 'Owner', 'email' => 'owner@example.test']);
    $other = SocialstreamActor::create(['name' => 'Other', 'email' => 'other@example.test']);
    $account = (new CreateConnectedAccount())->create($owner, 'github', OAuthOneUser::fake());
    $policy = new ConnectedAccountPolicy();

    expect(Gate::getPolicyFor($account))->toBeInstanceOf(ConnectedAccountPolicy::class)
        ->and($policy->viewAny($owner))->toBeTrue()
        ->and($policy->create($owner))->toBeTrue();
    foreach (['view', 'update', 'delete'] as $operation) {
        expect($policy->{$operation}($owner, $account))->toBeTrue()
            ->and($policy->{$operation}($other, $account))->toBeFalse();
    }
    expect(fn () => (new UpdateConnectedAccount())->update($other, $account, 'google', OAuthTwoUser::fake()))
        ->toThrow(AuthorizationException::class);
    expect($account->fresh()->provider)->toBe('github');
    $updated = (new UpdateConnectedAccount())->update($owner, $account, 'GOOGLE', OAuthTwoUser::fake(['name' => 'Updated']));

    expect($updated->provider)->toBe('google')
        ->and($updated->name)->toBe('Updated')
        ->and($updated->expires_at->equalTo(now()->addHour()))->toBeTrue();
});

it('refreshes persisted credentials through the registered provider boundary', function () {
    socialstreamAccountMigration()->up();
    $owner = SocialstreamActor::create(['name' => 'Owner', 'email' => 'owner@example.test']);
    $account = (new CreateConnectedAccount())->create($owner, 'contract-provider', OAuthOneUser::fake());
    $expiry = now()->addHours(2);
    Socialstream::refreshesTokensForProviderUsing('contract-provider', fn ($received) => new RefreshedCredentials('renewed', 'renewed-secret', 'renewed-refresh', $expiry));
    $updated = (new UpdateConnectedAccount())->updateRefreshToken($account);

    expect($updated->fresh()->token)->toBe('renewed')
        ->and($updated->secret)->toBe('renewed-secret')
        ->and($updated->refresh_token)->toBe('renewed-refresh')
        ->and($updated->expires_at->equalTo($expiry))->toBeTrue();
});

it('delegates redirects and user resolution and only generates missing emails when enabled', function () {
    $redirect = new RedirectResponse('https://provider.example.test/authorize');
    $provider = OAuthTwoUser::fake(['email' => null]);
    Socialite::shouldReceive('driver')->with('github')->andReturnSelf();
    Socialite::shouldReceive('redirect')->once()->andReturn($redirect);
    Socialite::shouldReceive('user')->andReturn($provider);

    expect((new GenerateRedirectForProvider())->generate('github'))->toBe($redirect)
        ->and((new ResolveSocialiteUser())->resolve('github')->getEmail())->toBeNull();
    config(['socialstream.features' => [Features::generateMissingEmails()], 'app.domain' => '.example.test']);

    expect((new ResolveSocialiteUser())->resolve('github')->getEmail())->toBe('123456789@github.example.test');
    $provider->email = 'provided@example.test';
    expect((new ResolveSocialiteUser())->resolve('github')->getEmail())->toBe('provided@example.test');
    $provider->email = null;
    config(['app.domain' => null]);
    expect((new ResolveSocialiteUser())->resolve('github')->getEmail())->toBe('123456789@github');
});

it('rethrows invalid OAuth state so the host can handle it', function () {
    $error = new InvalidStateException('state mismatch');

    expect(fn () => (new HandleInvalidState())->handle($error))->toThrow($error);
});

it('creates a verified user linked account and personal team in one transaction', function (bool $avatars) {
    socialstreamAccountMigration()->up();
    config(['socialstream.features' => $avatars ? [Features::providerAvatars()] : [], 'jetstream.features' => [JetstreamFeatures::profilePhotos()]]);
    $provider = OAuthTwoUser::fake(['name' => 'Test Person', 'email' => 'new@example.test']);
    $actor = (new CreateUserFromProvider(new CreateConnectedAccount()))->create('github', $provider);

    expect($actor->email_verified_at)->not->toBeNull()
        ->and($actor->profile_photo_path)->toBe($avatars ? $provider->getAvatar() : null)
        ->and(ConnectedAccount::query()->where('user_id', $actor->id)->count())->toBe(1)
        ->and($actor->ownedTeams()->first()->name)->toBe("Test's Team")
        ->and($actor->ownedTeams()->first()->personal_team)->toBeTrue();
})->with([true, false]);

it('rolls back user creation when linking the provider fails', function () {
    $linker = Mockery::mock(CreatesConnectedAccounts::class);
    $linker->shouldReceive('create')->once()->andThrow(new RuntimeException('linking failed'));

    expect(fn () => (new CreateUserFromProvider($linker))->create('github', OAuthTwoUser::fake()))
        ->toThrow(RuntimeException::class, 'linking failed');
    expect(SocialstreamActor::count())->toBe(0)->and(Team::count())->toBe(0);
});
