<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use JoelButcher\Socialstream\Contracts\AuthenticatesOAuthCallback;
use JoelButcher\Socialstream\Events\NewOAuthRegistration;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Two\User as SocialiteUser;
use Liberu\Foundation\Audit\Models\ActivityLogEntry;
use Liberu\Foundation\Currency\Services\CurrencyConverter;
use Liberu\Foundation\Identity\Jobs\SyncNewUserToCrm;
use Liberu\Foundation\Identity\Socialstream\Actions\VerifyTelegramAuthPayload;
use Liberu\Foundation\Identity\Socialstream\Http\Controllers\TelegramAuthController;
use Liberu\Foundation\Identity\Socialstream\Http\Responses\SpaAwareOAuthResponse;
use Liberu\Foundation\Identity\Socialstream\Listeners\SyncNewOAuthUserToCrm;
use Liberu\Foundation\Integrations\Contracts\IntegrationAdapter;
use Liberu\Foundation\Integrations\Contracts\OAuthIntegrationAdapter;
use Liberu\Foundation\Integrations\Support\IntegrationRegistry;
use Liberu\Foundation\Organizations\Actions\Jetstream\DeleteTeam;
use Liberu\Foundation\Organizations\Models\Team;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('covers ActivityLogEntry casts and morph relations', function (): void {
    $user = User::factory()->create();
    $entry = ActivityLogEntry::create([
        'log_name' => 'audit',
        'description' => 'created',
        'subject_type' => User::class,
        'subject_id' => $user->id,
        'causer_type' => User::class,
        'causer_id' => $user->id,
        'attribute_changes' => ['name' => 'Updated'],
        'properties' => ['ip' => '127.0.0.1'],
        'retain_until' => now()->addDays(30),
    ]);

    $fresh = ActivityLogEntry::findOrFail($entry->id);
    expect($fresh->attribute_changes)->toBe(['name' => 'Updated'])
        ->and($fresh->properties)->toBe(['ip' => '127.0.0.1'])
        ->and($fresh->retain_until)->not->toBeNull()
        ->and($fresh->subject()->getModel())->toBeInstanceOf(User::class)
        ->and($fresh->causer()->getModel())->toBeInstanceOf(User::class);
});

it('covers OpenExchangeRateApiProvider fallback URL when primary fails', function (): void {
    Cache::flush();
    config([
        'currency.exchange_rates_enabled' => true,
        'currency.exchange_rate_api_url' => 'https://primary-rates.example.test/{base}',
        'currency.exchange_rate_api_fallback_url' => 'https://fallback-rates.example.test/{base}',
        'currency.stale_after_seconds' => 600,
    ]);

    Http::fake([
        'https://primary-rates.example.test/USD' => Http::response(['result' => 'error'], 503),
        'https://fallback-rates.example.test/USD' => Http::response([
            'result' => 'success',
            'rates' => ['EUR' => 0.85],
            'time_last_update_unix' => 1700000000,
        ]),
    ]);

    expect(app(CurrencyConverter::class)->convertAmount(100, 'USD', 'EUR'))->toBe(85.0);
});

it('covers SyncNewUserToCrm job across unconfigured, missing user, success, backoff, and failed paths', function (): void {
    Http::fake([
        'https://crm.example.test/api/v1/crm/lead-capture' => Http::response(['ok' => true], 201),
    ]);

    $job = new SyncNewUserToCrm(999999, 'email', ['locale' => 'en'], ['ip' => '127.0.0.1']);
    expect($job->backoff())->toBe([10, 30, 60, 300, 900]);

    // Unconfigured -> no-op
    config(['services.crm.base_url' => null, 'services.crm.token' => null]);
    $job->handle();
    Http::assertNothingSent();

    // Configured, user missing -> no-op
    config([
        'services.crm.base_url' => 'https://crm.example.test',
        'services.crm.token' => 'secret-token',
        'services.crm.external_key_prefix' => 'user-',
    ]);
    $job->handle();
    Http::assertNothingSent();

    // Configured, user exists -> posts lead
    $user = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.test']);
    $realJob = new SyncNewUserToCrm($user->id, 'google', ['locale' => 'en'], ['ip' => '127.0.0.1']);
    $realJob->handle();
    Http::assertSentCount(1);

    // Failed handler logs error
    Log::shouldReceive('error')->once();
    $realJob->failed(new RuntimeException('CRM unreachable'));
});

it('covers VerifyTelegramAuthPayload across valid and invalid payloads', function (): void {
    $botToken = '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11';
    $verifier = new VerifyTelegramAuthPayload($botToken);

    // Non-scalar value rejected
    expect($verifier->verify(Request::create('/cb', 'GET', ['id' => ['nested'], 'auth_date' => (string) now()->timestamp, 'hash' => 'abc'])))->toBeNull();

    // Missing required fields or empty token rejected
    expect($verifier->verify(Request::create('/cb', 'GET', ['id' => '42'])))->toBeNull();
    expect((new VerifyTelegramAuthPayload(''))->verify(Request::create('/cb', 'GET', ['id' => '42', 'auth_date' => (string) now()->timestamp, 'hash' => 'abc'])))->toBeNull();

    // Helper to sign Telegram payload
    $sign = function (array $fields) use ($botToken): array {
        ksort($fields);
        $checkString = collect($fields)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $secretKey = hash('sha256', $botToken, true);
        $fields['hash'] = hash_hmac('sha256', $checkString, $secretKey);

        return $fields;
    };

    // Wrong hash rejected
    expect($verifier->verify(Request::create('/cb', 'GET', ['id' => '42', 'first_name' => 'Ada', 'auth_date' => (string) now()->timestamp, 'hash' => 'forged'])))->toBeNull();

    // Stale auth_date rejected
    $stale = $sign(['id' => '42', 'first_name' => 'Ada', 'auth_date' => (string) (now()->timestamp - 90000)]);
    expect($verifier->verify(Request::create('/cb', 'GET', $stale)))->toBeNull();

    // Valid fresh payload accepted
    $valid = $sign(['id' => '42', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'username' => 'ada', 'auth_date' => (string) now()->timestamp]);
    $verified = $verifier->verify(Request::create('/cb', 'GET', $valid));
    expect($verified)->toBeArray()->and($verified['id'])->toBe('42')->and($verified['username'])->toBe('ada');
});

it('covers TelegramAuthController invalid and valid callback flows', function (): void {
    $botToken = '123456:telegram-test-token';
    config(['services.telegram.bot_token' => $botToken, 'app.url' => 'https://example.test']);

    $controller = new TelegramAuthController();
    $authenticator = new class() implements AuthenticatesOAuthCallback
    {
        public ?string $provider = null;

        public ?SocialiteUserContract $user = null;

        public function authenticate(string $provider, SocialiteUserContract $providerAccount): RedirectResponse
        {
            $this->provider = $provider;
            $this->user = $providerAccount;

            return new RedirectResponse('/dashboard');
        }
    };

    // Invalid payload aborts 403
    expect(fn () => $controller->callback(Request::create('/oauth/telegram/callback', 'GET', ['hash' => 'bad']), $authenticator))
        ->toThrow(HttpException::class);

    // Valid payload authenticates and synthesizes email/name
    $fields = ['id' => '777', 'username' => 'tguser', 'auth_date' => (string) now()->timestamp];
    ksort($fields);
    $checkString = collect($fields)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
    $fields['hash'] = hash_hmac('sha256', $checkString, hash('sha256', $botToken, true));
    $fields['spa'] = '1';

    $response = $controller->callback(Request::create('/oauth/telegram/callback', 'GET', $fields), $authenticator);
    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($authenticator->provider)->toBe('telegram')
        ->and($authenticator->user?->getName())->toBe('Telegram User 777')
        ->and($authenticator->user?->getEmail())->toBe('telegram-777@users.example.test')
        ->and(session('socialstream.spa_redirect'))->toBeTrue();
});

it('covers SpaAwareOAuthResponse for both session and SPA flows', function (): void {
    $default = new class() implements Responsable
    {
        public function toResponse($request): Response
        {
            return new Response('default-session-response', 200);
        }
    };

    $responder = new SpaAwareOAuthResponse($default);

    // Non-SPA delegates to default response
    session()->forget('socialstream.spa_redirect');
    $req = Request::create('/oauth/google/callback', 'GET');
    expect($responder->toResponse($req)->getContent())->toBe('default-session-response');

    // SPA flow mints Sanctum token and redirects with fragment
    $user = User::factory()->create(['name' => 'SPA User', 'email' => 'spa@example.test']);
    $req->setUserResolver(fn () => $user);
    session(['socialstream.spa_redirect' => true]);
    config(['services.frontend.oauth_callback_url' => '/account/oauth-callback']);

    $redirect = $responder->toResponse($req);
    expect($redirect)->toBeInstanceOf(RedirectResponse::class)
        ->and($redirect->getTargetUrl())->toContain('/account/oauth-callback#token=')
        ->and($redirect->getTargetUrl())->toContain('email=spa%40example.test');
});

it('covers SyncNewOAuthUserToCrm listener', function (): void {
    Queue::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $socialiteUser = (new SocialiteUser())->map([
        'id' => 'oauth-123',
        'nickname' => 'jdoe',
        'name' => $user->name,
        'email' => $user->email,
    ]);

    $event = new NewOAuthRegistration($user, 'google', $socialiteUser);
    (new SyncNewOAuthUserToCrm())->handle($event);

    Queue::assertPushed(SyncNewUserToCrm::class);
});

it('covers IntegrationRegistry oauth lookup for both OAuth and non-OAuth adapters', function (): void {
    $registry = new IntegrationRegistry();

    $plain = new class() implements IntegrationAdapter
    {
        public function name(): string
        {
            return 'plain';
        }

        public function capabilities(): array
        {
            return ['sync'];
        }

        public function test(array $credentials): bool
        {
            return true;
        }
    };

    $oauth = new class() implements OAuthIntegrationAdapter
    {
        public function name(): string
        {
            return 'oauth-provider';
        }

        public function capabilities(): array
        {
            return ['oauth'];
        }

        public function test(array $credentials): bool
        {
            return true;
        }

        public function authorizationUrl(string $state): string
        {
            return 'https://oauth.example.test/authorize?state='.$state;
        }

        public function exchangeAuthorizationCode(string $code): array
        {
            return ['access_token' => $code];
        }

        public function refreshAccessToken(string $refreshToken): array
        {
            return ['access_token' => $refreshToken];
        }
    };

    $registry->register($plain);
    $registry->register($oauth);

    expect($registry->oauth('oauth-provider'))->toBe($oauth);
    expect(fn () => $registry->oauth('plain'))->toThrow(InvalidArgumentException::class, 'does not support OAuth');
});

it('covers Jetstream DeleteTeam action', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $user->id, 'personal_team' => false]);

    (new DeleteTeam())->delete($team);

    expect(Team::withArchived()->find($team->id))->toBeNull();
});
