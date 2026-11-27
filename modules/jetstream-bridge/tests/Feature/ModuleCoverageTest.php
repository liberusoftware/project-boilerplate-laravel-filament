<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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

it('boots FortifyServiceProvider defaults and all rate limiters', function (): void {
    $provider = $this->app->register(FortifyServiceProvider::class, force: true);
    $provider->register();
    $provider->boot();

    expect(Password::default())->toBeInstanceOf(Password::class);

    $loginRequest = Request::create('/login', 'POST', ['email' => 'User@Example.Test']);
    $loginRequest->server->set('REMOTE_ADDR', '127.0.0.1');
    expect(RateLimiter::limiter('login')($loginRequest))->toBeInstanceOf(Limit::class);

    $twoFactorRequest = Request::create('/two-factor-challenge', 'POST');
    $twoFactorRequest->setLaravelSession(app('session.store'));
    $twoFactorRequest->session()->put('login.id', 7);
    expect(RateLimiter::limiter('two-factor')($twoFactorRequest))->toBeInstanceOf(Limit::class);

    $passkeyRequest = Request::create('/passkeys/assert', 'POST', ['credential' => ['id' => 'cred-abc']]);
    $passkeyRequest->setLaravelSession(app('session.store'));
    $passkeyRequest->server->set('REMOTE_ADDR', '127.0.0.1');
    expect(RateLimiter::limiter('passkeys')($passkeyRequest))->toBeInstanceOf(Limit::class);

    // Fallback to session ID when credential.id is empty
    $passkeyNoCred = Request::create('/passkeys/assert', 'POST');
    $passkeyNoCred->setLaravelSession(app('session.store'));
    $passkeyNoCred->server->set('REMOTE_ADDR', '127.0.0.1');
    expect(RateLimiter::limiter('passkeys')($passkeyNoCred))->toBeInstanceOf(Limit::class);
});

it('creates new users under open policy and rejects closed or uninvited registrations', function (): void {
    Event::fake([IdentityEvent::class]);

    $originalUserModel = config('auth.providers.users.model');
    $userModel = new class() extends User
    {
        protected $table = 'users';

        protected $fillable = ['name', 'email', 'password'];
    };
    config()->set('auth.providers.users.model', $userModel::class);

    try {
        $closed = new CreateNewUser(
            new ConfiguredRegistrationPolicy('closed'),
            new RejectingInvitationValidator(),
            new IdentifierNormalizer(),
        );
        expect(fn () => $closed->create([
            'name' => 'Blocked',
            'email' => 'blocked@example.test',
            'password' => 'StrongPass#1234',
            'password_confirmation' => 'StrongPass#1234',
        ]))->toThrow(ValidationException::class);

        $inviteOnly = new CreateNewUser(
            new ConfiguredRegistrationPolicy('invitation'),
            new RejectingInvitationValidator(),
            new IdentifierNormalizer(),
        );
        expect(fn () => $inviteOnly->create([
            'name' => 'Uninvited',
            'email' => 'uninvited@example.test',
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
            'email' => '  Ada.Cov@Example.TEST ',
            'password' => 'StrongPass#1234',
            'password_confirmation' => 'StrongPass#1234',
        ]);

        expect($created->email)->toBe('ada.cov@example.test')
            ->and(Hash::check('StrongPass#1234', $created->password))->toBeTrue();

        Event::assertDispatched(IdentityEvent::class, fn (IdentityEvent $e): bool => $e->name === 'identity.registered');
    } finally {
        config()->set('auth.providers.users.model', $originalUserModel);
    }
});

it('resets and updates passwords and updates profile information for standard and verifiable users', function (): void {
    Event::fake([IdentityEvent::class]);

    $user = new class() extends User
    {
        protected $table = 'users';

        protected $fillable = ['name', 'email', 'password'];
    };
    $user = $user::create([
        'name' => 'Grace Hopper',
        'email' => 'grace.cov@example.test',
        'password' => Hash::make('InitialPass#1234'),
    ]);

    (new ResetUserPassword())->reset($user, [
        'password' => 'ResetPass#5678',
        'password_confirmation' => 'ResetPass#5678',
    ]);
    expect(Hash::check('ResetPass#5678', $user->fresh()->password))->toBeTrue();
    Event::assertDispatched(IdentityEvent::class, fn (IdentityEvent $e): bool => $e->name === 'identity.password.reset');

    $this->actingAs($user->fresh(), 'web');
    (new UpdateUserPassword())->update($user->fresh(), [
        'current_password' => 'ResetPass#5678',
        'password' => 'ChangedPass#9012',
        'password_confirmation' => 'ChangedPass#9012',
    ]);
    expect(Hash::check('ChangedPass#9012', $user->fresh()->password))->toBeTrue();
    Event::assertDispatched(IdentityEvent::class, fn (IdentityEvent $e): bool => $e->name === 'identity.password.changed');

    $profileAction = new UpdateUserProfileInformation(new IdentifierNormalizer());
    $profileAction->update($user->fresh(), [
        'name' => 'Rear Admiral Grace',
        'email' => 'grace.cov@example.test',
    ]);
    expect($user->fresh()->name)->toBe('Rear Admiral Grace');

    $verifiable = new class() extends User implements MustVerifyEmail
    {
        protected $table = 'users';

        protected $fillable = ['name', 'email', 'email_verified_at', 'password'];

        public bool $notified = false;

        public function sendEmailVerificationNotification(): void
        {
            $this->notified = true;
        }
    };
    $verifiable = $verifiable::create([
        'name' => 'Katherine',
        'email' => 'katherine.cov@example.test',
        'email_verified_at' => now(),
        'password' => Hash::make('SecretPass#1234'),
    ]);

    $profileAction->update($verifiable, [
        'name' => 'Katherine Johnson',
        'email' => 'KJ.Cov@Example.TEST',
    ]);

    expect($verifiable->notified)->toBeTrue()
        ->and($verifiable->fresh()->email)->toBe('kj.cov@example.test')
        ->and($verifiable->fresh()->email_verified_at)->toBeNull();
});

it('deletes users and associated resources and rejects non-user models', function (): void {
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

    $log = [];
    $makeDeletable = static fn (string $tag) => new class($tag, $log)
    {
        public function __construct(private string $tag, private array &$log) {}

        public function delete(): void
        {
            $this->log[] = $this->tag;
        }
    };

    $user = new class($makeDeletable('account'), $makeDeletable('token'), $log) extends User
    {
        public array $log;

        public Collection $ownedTeams;

        public Collection $connectedAccounts;

        public Collection $tokens;

        public function __construct(?object $account = null, ?object $token = null, array &$log = [])
        {
            parent::__construct();
            $this->log = &$log;
            $this->ownedTeams = collect(['owned-team-1']);
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

    expect($deletedTeams)->toBe(['owned-team-1'])
        ->and($log)->toBe(['teams_detached', 'photo_deleted', 'account', 'token', 'user_deleted']);
});
