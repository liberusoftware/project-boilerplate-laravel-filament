<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Liberu\Foundation\Identity\Events\IdentityEvent;
use Liberu\Foundation\Identity\Support\ConfiguredRegistrationPolicy;
use Liberu\Foundation\Identity\Support\IdentifierNormalizer;
use Liberu\Foundation\Identity\Support\RejectingInvitationValidator;
use Liberu\Foundation\JetstreamBridge\Actions\Fortify\CreateNewUser;
use Liberu\Foundation\JetstreamBridge\Actions\Fortify\ResetUserPassword;
use Liberu\Foundation\JetstreamBridge\Actions\Fortify\UpdateUserPassword;
use Liberu\Foundation\JetstreamBridge\Actions\Fortify\UpdateUserProfileInformation;
use Liberu\Foundation\JetstreamBridge\Actions\Jetstream\DeleteUser;
use Liberu\Foundation\JetstreamBridge\Providers\FortifyServiceProvider;

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->timestamps();
        });
    }
});

it('boots FortifyServiceProvider defaults and rate limiters', function () {
    $provider = new FortifyServiceProvider(app());
    $provider->register();
    $provider->boot();

    expect(Password::default())->toBeInstanceOf(Password::class);

    $loginRequest = Request::create('/login', 'POST', ['email' => 'Ada@Example.Test']);
    $loginRequest->server->set('REMOTE_ADDR', '127.0.0.1');
    $loginLimit = RateLimiter::limiter('login')($loginRequest);
    expect($loginLimit)->toBeInstanceOf(Limit::class);

    $twoFactorRequest = Request::create('/two-factor-challenge', 'POST');
    $twoFactorRequest->setLaravelSession(app('session.store'));
    $twoFactorRequest->session()->put('login.id', 42);
    $twoFactorLimit = RateLimiter::limiter('two-factor')($twoFactorRequest);
    expect($twoFactorLimit)->toBeInstanceOf(Limit::class);

    $passkeyRequest = Request::create('/passkeys/assert', 'POST', ['credential' => ['id' => 'cred-1']]);
    $passkeyRequest->setLaravelSession(app('session.store'));
    $passkeyRequest->server->set('REMOTE_ADDR', '127.0.0.1');
    $passkeyLimit = RateLimiter::limiter('passkeys')($passkeyRequest);
    expect($passkeyLimit)->toBeInstanceOf(Limit::class);
});

it('creates new users when registration is permitted and blocks when closed or uninvited', function () {
    Event::fake([IdentityEvent::class]);

    $userModel = new class() extends User
    {
        protected $table = 'users';

        protected $fillable = ['name', 'email', 'password'];
    };
    config()->set('auth.providers.users.model', $userModel::class);

    $closed = new CreateNewUser(
        new ConfiguredRegistrationPolicy('closed'),
        new RejectingInvitationValidator(),
        new IdentifierNormalizer(),
    );

    expect(fn () => $closed->create([
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'password' => 'StrongPass#1234',
        'password_confirmation' => 'StrongPass#1234',
    ]))->toThrow(ValidationException::class);

    $open = new CreateNewUser(
        new ConfiguredRegistrationPolicy('open'),
        new RejectingInvitationValidator(),
        new IdentifierNormalizer(),
    );

    $created = $open->create([
        'name' => 'Ada Lovelace',
        'email' => '  Ada@Example.TEST ',
        'password' => 'StrongPass#1234',
        'password_confirmation' => 'StrongPass#1234',
    ]);

    expect($created->email)->toBe('ada@example.test')
        ->and(Hash::check('StrongPass#1234', $created->password))->toBeTrue();

    Event::assertDispatched(IdentityEvent::class, fn (IdentityEvent $e) => $e->name === 'identity.registered');
});

it('resets and updates user passwords', function () {
    Event::fake([IdentityEvent::class]);

    $user = new class() extends User
    {
        protected $table = 'users';

        protected $fillable = ['name', 'email', 'password'];
    };
    $user = $user::create([
        'name' => 'Grace Hopper',
        'email' => 'grace@example.test',
        'password' => Hash::make('OldPassword#1234'),
    ]);

    (new ResetUserPassword())->reset($user, [
        'password' => 'ResetPass#5678',
        'password_confirmation' => 'ResetPass#5678',
    ]);

    expect(Hash::check('ResetPass#5678', $user->fresh()->password))->toBeTrue();
    Event::assertDispatched(IdentityEvent::class, fn (IdentityEvent $e) => $e->name === 'identity.password.reset');

    $this->actingAs($user->fresh(), 'web');

    (new UpdateUserPassword())->update($user->fresh(), [
        'current_password' => 'ResetPass#5678',
        'password' => 'UpdatedPass#9012',
        'password_confirmation' => 'UpdatedPass#9012',
    ]);

    expect(Hash::check('UpdatedPass#9012', $user->fresh()->password))->toBeTrue();
    Event::assertDispatched(IdentityEvent::class, fn (IdentityEvent $e) => $e->name === 'identity.password.changed');
});

it('updates profile information for standard and MustVerifyEmail users', function () {
    Event::fake([IdentityEvent::class]);

    $action = new UpdateUserProfileInformation(new IdentifierNormalizer());

    $standardUser = new class() extends User
    {
        protected $table = 'users';

        protected $fillable = ['name', 'email', 'password'];
    };
    $standardUser = $standardUser::create([
        'name' => 'Alan',
        'email' => 'alan@example.test',
        'password' => Hash::make('Secret#123456'),
    ]);

    $action->update($standardUser, [
        'name' => 'Alan Turing',
        'email' => 'alan@example.test',
    ]);

    expect($standardUser->fresh()->name)->toBe('Alan Turing');

    $verifiableUser = new class() extends User implements MustVerifyEmail
    {
        protected $table = 'users';

        protected $fillable = ['name', 'email', 'email_verified_at', 'password'];

        public bool $verificationSent = false;

        public function sendEmailVerificationNotification(): void
        {
            $this->verificationSent = true;
        }
    };
    $verifiableUser = $verifiableUser::create([
        'name' => 'Katherine',
        'email' => 'katherine@example.test',
        'email_verified_at' => now(),
        'password' => Hash::make('Secret#123456'),
    ]);

    $action->update($verifiableUser, [
        'name' => 'Katherine Johnson',
        'email' => 'K.Johnson@Example.TEST',
    ]);

    expect($verifiableUser->verificationSent)->toBeTrue()
        ->and($verifiableUser->fresh()->email)->toBe('k.johnson@example.test')
        ->and($verifiableUser->fresh()->email_verified_at)->toBeNull();

    Event::assertDispatched(IdentityEvent::class, fn (IdentityEvent $e) => $e->name === 'identity.profile.updated' && $e->context['email_changed'] === true);
});

it('deletes users with teams, profile photos, connected accounts, and tokens, and rejects non-users', function () {
    $deletedTeams = [];

    $deletesTeams = new class($deletedTeams) implements DeletesTeams
    {
        public function __construct(public array &$deletedTeams) {}

        public function delete(mixed $team): void
        {
            $this->deletedTeams[] = $team;
        }
    };

    $action = new DeleteUser($deletesTeams);

    expect(fn () => $action->delete(new stdClass()))
        ->toThrow(InvalidArgumentException::class, 'Only Laravel user models can be deleted.');

    $deletable = static fn (string $label, array &$log): object => new class($label, $log)
    {
        public function __construct(private string $label, private array &$log) {}

        public function delete(): void
        {
            $this->log[] = $this->label;
        }
    };

    $log = [];
    $account = $deletable('connected_account', $log);
    $token = $deletable('token', $log);

    $user = new class($account, $token, $log) extends User
    {
        public array $log;

        public Collection $ownedTeams;

        public Collection $connectedAccounts;

        public Collection $tokens;

        public function __construct(?object $account = null, ?object $token = null, array &$log = [])
        {
            parent::__construct();
            $this->log = &$log;
            $this->ownedTeams = collect(['team-a', 'team-b']);
            $this->connectedAccounts = collect($account ? [$account] : []);
            $this->tokens = collect($token ? [$token] : []);
        }

        public function teams(): object
        {
            return new class($this->log)
            {
                public function __construct(private array &$log) {}

                public function detach(): void
                {
                    $this->log[] = 'teams_detached';
                }
            };
        }

        public function deleteProfilePhoto(): void
        {
            $this->log[] = 'photo_deleted';
        }

        public function delete(): bool
        {
            $this->log[] = 'user_deleted';

            return true;
        }
    };

    $action->delete($user);

    expect($deletedTeams)->toBe(['team-a', 'team-b'])
        ->and($log)->toBe([
            'teams_detached',
            'photo_deleted',
            'connected_account',
            'token',
            'user_deleted',
        ]);
});
