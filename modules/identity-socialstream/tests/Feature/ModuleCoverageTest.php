<?php

declare(strict_types=1);

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use JoelButcher\Socialstream\Contracts\AuthenticatesOAuthCallback;
use JoelButcher\Socialstream\Events\NewOAuthRegistration;
use JoelButcher\Socialstream\Features;
use JoelButcher\Socialstream\RefreshedCredentials;
use JoelButcher\Socialstream\Socialstream;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Foundation\Identity\Jobs\SyncNewUserToCrm;
use Liberu\Foundation\Identity\Socialstream\Actions\CreateConnectedAccount;
use Liberu\Foundation\Identity\Socialstream\Actions\CreateUserFromProvider;
use Liberu\Foundation\Identity\Socialstream\Actions\GenerateRedirectForProvider;
use Liberu\Foundation\Identity\Socialstream\Actions\HandleInvalidState;
use Liberu\Foundation\Identity\Socialstream\Actions\ResolveSocialiteUser;
use Liberu\Foundation\Identity\Socialstream\Actions\SetUserPassword;
use Liberu\Foundation\Identity\Socialstream\Actions\UpdateConnectedAccount;
use Liberu\Foundation\Identity\Socialstream\Actions\VerifyTelegramAuthPayload;
use Liberu\Foundation\Identity\Socialstream\Contracts\ConnectedAccountOwner;
use Liberu\Foundation\Identity\Socialstream\Http\Controllers\TelegramAuthController;
use Liberu\Foundation\Identity\Socialstream\Http\Responses\SpaAwareOAuthResponse;
use Liberu\Foundation\Identity\Socialstream\Listeners\SyncNewOAuthUserToCrm;
use Liberu\Foundation\Identity\Socialstream\Models\ConnectedAccount;
use Liberu\Foundation\Identity\Socialstream\Policies\ConnectedAccountPolicy;
use Liberu\Foundation\Identity\Socialstream\Providers\SocialstreamServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('boots socialstream service provider and covers ConnectedAccount model and policy', function (): void {
    $provider = $this->app->register(SocialstreamServiceProvider::class, force: true);
    $provider->register();
    $provider->boot();

    $factoryAccount = ConnectedAccount::factory()->make([
        'token' => 'secret-tok',
        'secret' => 'secret-sec',
        'refresh_token' => 'secret-ref',
    ]);
    $serialized = $factoryAccount->toArray();

    expect($factoryAccount)->toBeInstanceOf(ConnectedAccount::class)
        ->and($serialized)->not->toHaveKey('token')
        ->and($serialized)->not->toHaveKey('secret')
        ->and($serialized)->not->toHaveKey('refresh_token');

    $owner = new class() implements ConnectedAccountOwner
    {
        public bool $owns = true;

        public function ownsConnectedAccount($account): bool
        {
            return $this->owns;
        }
    };

    $policy = new ConnectedAccountPolicy();
    expect($policy->viewAny($owner))->toBeTrue()
        ->and($policy->create($owner))->toBeTrue()
        ->and($policy->view($owner, $factoryAccount))->toBeTrue()
        ->and($policy->update($owner, $factoryAccount))->toBeTrue()
        ->and($policy->delete($owner, $factoryAccount))->toBeTrue();

    $owner->owns = false;
    expect($policy->view($owner, $factoryAccount))->toBeFalse()
        ->and($policy->update($owner, $factoryAccount))->toBeFalse()
        ->and($policy->delete($owner, $factoryAccount))->toBeFalse();
});

it('covers CreateConnectedAccount, UpdateConnectedAccount, CreateUserFromProvider, and SetUserPassword', function (): void {
    Socialstream::useConnectedAccountModel(ConnectedAccount::class);
    Gate::policy(ConnectedAccount::class, ConnectedAccountPolicy::class);

    $createAccount = new CreateConnectedAccount();
    $providerUser = (new SocialiteUser())->map([
        'id' => 'prov-100',
        'name' => 'OAuth User',
        'nickname' => 'oauth_u',
        'email' => 'oauth.cov@example.test',
        'avatar' => null,
    ])->setToken('tok-1')->setRefreshToken('ref-1')->setExpiresIn(3600);

    expect(fn () => $createAccount->create(new stdClass(), 'google', $providerUser))
        ->toThrow(InvalidArgumentException::class, 'Connected accounts require an Eloquent user model.');

    $user = User::factory()->create();
    $account = $createAccount->create($user, 'Google', $providerUser);

    expect($account)->toBeInstanceOf(ConnectedAccount::class)
        ->and($account->provider)->toBe('google')
        ->and($account->provider_id)->toBe('prov-100')
        ->and($account->expires_at)->not->toBeNull();

    // UpdateConnectedAccount::update with authorized owner
    $owner = new class() implements ConnectedAccountOwner
    {
        public function ownsConnectedAccount($account): bool
        {
            return true;
        }
    };
    $updatedProviderUser = (new SocialiteUser())->map([
        'id' => 'prov-100',
        'name' => 'OAuth User Updated',
        'nickname' => 'oauth_u2',
        'email' => 'oauth.cov@example.test',
        'avatar' => null,
    ])->setToken('tok-2')->setRefreshToken('ref-2')->setExpiresIn(7200);

    $updateAccount = new UpdateConnectedAccount();
    $updated = $updateAccount->update($owner, $account, 'GOOGLE', $updatedProviderUser);
    expect($updated->name)->toBe('OAuth User Updated')
        ->and($updated->token)->toBe('tok-2');

    // UpdateConnectedAccount::updateRefreshToken with registered refresher
    Socialstream::refreshesTokensForProviderUsing('google', fn (): RefreshedCredentials => new RefreshedCredentials(
        token: 'refreshed-tok',
        tokenSecret: 'refreshed-sec',
        refreshToken: 'refreshed-ref',
        expiry: now()->addHours(2),
    ));
    $refreshed = $updateAccount->updateRefreshToken($updated);
    expect($refreshed->token)->toBe('refreshed-tok')
        ->and($refreshed->secret)->toBe('refreshed-sec')
        ->and($refreshed->refresh_token)->toBe('refreshed-ref');

    // CreateUserFromProvider creates user, verified email, connected account, and personal team
    $newProviderUser = (new SocialiteUser())->map([
        'id' => 'prov-200',
        'name' => 'Social Newcomer',
        'nickname' => 'newcomer',
        'email' => 'newcomer.cov@example.test',
        'avatar' => null,
    ])->setToken('tok-new');

    $creator = new CreateUserFromProvider($createAccount);
    $createdUser = $creator->create('google', $newProviderUser);

    expect($createdUser->email)->toBe('newcomer.cov@example.test')
        ->and($createdUser->email_verified_at)->not->toBeNull()
        ->and($createdUser->ownedTeams()->count())->toBe(1);

    // SetUserPassword rejects non-model and sets password on valid model
    $setPassword = new SetUserPassword();
    expect(fn () => $setPassword->set(new stdClass(), ['password' => 'StrongPass#1234', 'password_confirmation' => 'StrongPass#1234']))
        ->toThrow(InvalidArgumentException::class, 'Passwords can only be set on Eloquent user models.');

    $setPassword->set($user, [
        'password' => 'StrongPass#1234',
        'password_confirmation' => 'StrongPass#1234',
    ]);
    expect(Hash::check('StrongPass#1234', $user->fresh()->password))->toBeTrue();
});

it('covers GenerateRedirectForProvider, HandleInvalidState, and ResolveSocialiteUser', function (): void {
    $expectedRedirect = new RedirectResponse('https://accounts.google.com/o/oauth2/auth');
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('redirect')->once()->andReturn($expectedRedirect);

    $socialiteUser = (new SocialiteUser())->map([
        'id' => 'no-email-42',
        'name' => 'No Email',
        'email' => null,
    ]);
    $driver->shouldReceive('user')->once()->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('google')->twice()->andReturn($driver);

    expect((new GenerateRedirectForProvider())->generate('google'))->toBe($expectedRedirect);

    config()->set('socialstream.features', [Features::generateMissingEmails()]);
    config()->set('app.domain', '.example.test');
    $resolved = (new ResolveSocialiteUser())->resolve('google');
    expect($resolved->getEmail())->toBe('no-email-42@google.example.test');

    expect(fn () => (new HandleInvalidState())->handle(new InvalidStateException('bad state')))
        ->toThrow(InvalidStateException::class, 'bad state');
});

it('covers Telegram verification, TelegramAuthController, SpaAwareOAuthResponse, and SyncNewOAuthUserToCrm', function (): void {
    $botToken = '999999:cov-telegram-token';
    $verifier = new VerifyTelegramAuthPayload($botToken);
    $now = now()->timestamp;

    $sign = static function (array $fields) use ($botToken): array {
        ksort($fields);
        $check = collect($fields)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $fields['hash'] = hash_hmac('sha256', $check, hash('sha256', $botToken, true));

        return $fields;
    };

    expect($verifier->verify(Request::create('/cb', 'GET', ['id' => ['bad'], 'auth_date' => (string) $now, 'hash' => 'x'])))->toBeNull()
        ->and($verifier->verify(Request::create('/cb', 'GET', ['id' => '1'])))->toBeNull();

    $valid = $sign(['id' => '777', 'first_name' => 'Ada', 'last_name' => 'L', 'username' => 'ada', 'auth_date' => (string) $now]);
    expect($verifier->verify(Request::create('/cb', 'GET', $valid)))->toBeArray();
    expect($verifier->verify(Request::create('/cb', 'GET', [...$valid, 'hash' => str_repeat('0', 64)])))->toBeNull();
    expect($verifier->verify(Request::create('/cb', 'GET', $sign(['id' => '777', 'auth_date' => (string) ($now - 90000)]))))->toBeNull();

    config()->set('services.telegram.bot_token', $botToken);
    config()->set('app.url', 'https://erp.example.test');

    $controller = new TelegramAuthController();
    $auth = Mockery::mock(AuthenticatesOAuthCallback::class);
    expect(fn () => $controller->callback(Request::create('/oauth/telegram/callback', 'GET'), $auth))
        ->toThrow(HttpException::class);

    $expectedResp = new RedirectResponse('/home');
    $auth->shouldReceive('authenticate')->once()->andReturn($expectedResp);
    expect($controller->callback(Request::create('/oauth/telegram/callback', 'GET', [...$valid, 'spa' => '1']), $auth))
        ->toBe($expectedResp);

    // SpaAwareOAuthResponse: default flow + SPA redirect flow
    $defaultResp = new Response('default-ok');
    $default = new class($defaultResp) implements Responsable
    {
        public function __construct(private readonly Response $resp) {}

        public function toResponse($request): Response
        {
            return $this->resp;
        }
    };
    $spa = new SpaAwareOAuthResponse($default);

    session()->forget('socialstream.spa_redirect');
    $req = Request::create('/oauth/google/callback', 'GET');
    expect($spa->toResponse($req))->toBe($defaultResp);

    session(['socialstream.spa_redirect' => true]);
    $req->setUserResolver(fn () => new class()
    {
        public int $id = 5;

        public string $name = 'SPA User';

        public string $email = 'spa@example.test';

        public function createToken(string $name): object
        {
            return (object) ['plainTextToken' => 'spa-tok-1'];
        }
    });
    expect($spa->toResponse($req))->toBeInstanceOf(RedirectResponse::class);

    // SyncNewOAuthUserToCrm listener
    Bus::fake();
    $oauthUser = (object) ['id' => 5, 'currentTeam' => null, 'created_at' => now()];
    $providerAcc = (new SocialiteUser())->map(['id' => 'g-1', 'nickname' => 'spa']);
    (new SyncNewOAuthUserToCrm())->handle(new NewOAuthRegistration($oauthUser, 'google', $providerAcc));
    Bus::assertDispatched(SyncNewUserToCrm::class);
});
