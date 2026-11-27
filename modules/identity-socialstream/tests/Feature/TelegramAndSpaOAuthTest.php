<?php

declare(strict_types=1);

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use JoelButcher\Socialstream\Contracts\AuthenticatesOAuthCallback;
use JoelButcher\Socialstream\Events\NewOAuthRegistration;
use Laravel\Socialite\Two\User as SocialiteUser;
use Liberu\Foundation\Identity\Jobs\SyncNewUserToCrm;
use Liberu\Foundation\Identity\Socialstream\Actions\VerifyTelegramAuthPayload;
use Liberu\Foundation\Identity\Socialstream\Http\Controllers\TelegramAuthController;
use Liberu\Foundation\Identity\Socialstream\Http\Responses\SpaAwareOAuthResponse;
use Liberu\Foundation\Identity\Socialstream\Listeners\SyncNewOAuthUserToCrm;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

function signTelegramPayload(array $fields, string $botToken): array
{
    ksort($fields);
    $checkString = collect($fields)->map(fn ($value, $key) => "{$key}={$value}")->implode("\n");
    $secretKey = hash('sha256', $botToken, true);
    $fields['hash'] = hash_hmac('sha256', $checkString, $secretKey);

    return $fields;
}

it('verifies valid Telegram payloads and rejects malformed, forged, or stale payloads', function () {
    $botToken = '123456:telegram-secret-bot-token';
    $verifier = new VerifyTelegramAuthPayload($botToken);
    $now = now()->timestamp;

    // Non-scalar field rejected.
    $nonScalarRequest = Request::create('/oauth/telegram/callback', 'GET', [
        'id' => ['nested'],
        'auth_date' => (string) $now,
        'hash' => 'abc',
    ]);
    expect($verifier->verify($nonScalarRequest))->toBeNull();

    // Missing required fields or empty bot token rejected.
    expect($verifier->verify(Request::create('/oauth/telegram/callback', 'GET', ['id' => '1'])))->toBeNull();
    $validFields = signTelegramPayload([
        'id' => '987654',
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'username' => 'adalovelace',
        'photo_url' => 'https://t.me/i/userpic/ada.jpg',
        'auth_date' => (string) $now,
    ], $botToken);
    expect((new VerifyTelegramAuthPayload(''))->verify(Request::create('/oauth/telegram/callback', 'GET', $validFields)))->toBeNull();

    // Forged hash rejected.
    $forged = $validFields;
    $forged['hash'] = str_repeat('a', 64);
    expect($verifier->verify(Request::create('/oauth/telegram/callback', 'GET', $forged)))->toBeNull();

    // Stale auth_date rejected.
    $stale = signTelegramPayload([
        'id' => '987654',
        'first_name' => 'Ada',
        'auth_date' => (string) ($now - 90000),
    ], $botToken);
    expect($verifier->verify(Request::create('/oauth/telegram/callback', 'GET', $stale)))->toBeNull();

    // Future auth_date rejected.
    $future = signTelegramPayload([
        'id' => '987654',
        'first_name' => 'Ada',
        'auth_date' => (string) ($now + 120),
    ], $botToken);
    expect($verifier->verify(Request::create('/oauth/telegram/callback', 'GET', $future)))->toBeNull();

    // Non-digit auth_date rejected.
    $nonDigit = signTelegramPayload([
        'id' => '987654',
        'first_name' => 'Ada',
        'auth_date' => 'not-a-timestamp',
    ], $botToken);
    expect($verifier->verify(Request::create('/oauth/telegram/callback', 'GET', $nonDigit)))->toBeNull();

    // Valid payload returns data without hash.
    $verified = $verifier->verify(Request::create('/oauth/telegram/callback', 'GET', $validFields));
    expect($verified)->toBeArray()
        ->and($verified)->not->toHaveKey('hash')
        ->and($verified['id'])->toBe('987654')
        ->and($verified['first_name'])->toBe('Ada');
});

it('handles TelegramAuthController callback for both invalid and valid payloads', function () {
    $botToken = '123456:controller-bot-token';
    config()->set('services.telegram.bot_token', $botToken);
    config()->set('app.url', 'https://billing.example.test');

    $controller = new TelegramAuthController();
    $authenticator = Mockery::mock(AuthenticatesOAuthCallback::class);

    // Invalid payload aborts with 403.
    expect(fn () => $controller->callback(Request::create('/oauth/telegram/callback', 'GET'), $authenticator))
        ->toThrow(HttpException::class, 'Invalid Telegram login payload.');

    // Valid payload with name and spa=1.
    $fields = signTelegramPayload([
        'id' => '555',
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'username' => 'grace',
        'photo_url' => 'https://t.me/i/grace.jpg',
        'auth_date' => (string) now()->timestamp,
    ], $botToken);
    $fields['spa'] = '1';

    $expectedResponse = new RedirectResponse('/dashboard');

    $authenticator->shouldReceive('authenticate')
        ->once()
        ->with('telegram', Mockery::on(function (SocialiteUser $user): bool {
            return $user->getId() === '555'
                && $user->getNickname() === 'grace'
                && $user->getName() === 'Grace Hopper'
                && $user->getEmail() === 'telegram-555@users.billing.example.test'
                && $user->getAvatar() === 'https://t.me/i/grace.jpg'
                && $user->token === 'telegram';
        }))
        ->andReturn($expectedResponse);

    $response = $controller->callback(Request::create('/oauth/telegram/callback', 'GET', $fields), $authenticator);

    expect($response)->toBe($expectedResponse)
        ->and(session('socialstream.spa_redirect'))->toBeTrue()
        ->and(session()->has('socialstream.previous_url'))->toBeTrue();
});

it('returns default response or mints SPA redirect fragment in SpaAwareOAuthResponse', function () {
    $defaultResponse = new Response('session-flow');
    $defaultResponsable = new class($defaultResponse) implements Responsable
    {
        public function __construct(private readonly Response $response) {}

        public function toResponse($request): Response
        {
            return $this->response;
        }
    };

    $responder = new SpaAwareOAuthResponse($defaultResponsable);

    // Without spa_redirect session flag, delegates to default response.
    session()->forget('socialstream.spa_redirect');
    $request = Request::create('/oauth/google/callback', 'GET');
    expect($responder->toResponse($request))->toBe($defaultResponse);

    // With spa_redirect session flag, mints token and redirects to SPA callback with fragment.
    config()->set('services.frontend.token_name', 'nuxt-spa');
    config()->set('services.frontend.oauth_callback_url', '/account/oauth-callback');
    session(['socialstream.spa_redirect' => true]);

    $fakeUser = new class()
    {
        public int $id = 99;

        public string $name = 'Ada Lovelace';

        public string $email = 'ada@example.test';

        public function createToken(string $name): object
        {
            expect($name)->toBe('nuxt-spa');

            return (object) ['plainTextToken' => 'plain-token-123'];
        }
    };
    $request->setUserResolver(fn () => $fakeUser);

    $redirect = $responder->toResponse($request);

    expect($redirect)->toBeInstanceOf(RedirectResponse::class)
        ->and(session('socialstream.spa_redirect'))->toBeNull()
        ->and($redirect->getTargetUrl())->toContain('/account/oauth-callback#')
        ->and($redirect->getTargetUrl())->toContain('token=plain-token-123')
        ->and($redirect->getTargetUrl())->toContain('id=99')
        ->and($redirect->getTargetUrl())->toContain('email=ada%40example.test');
});

it('dispatches SyncNewUserToCrm when handling NewOAuthRegistration', function () {
    Bus::fake();

    $user = (object) [
        'id' => 123,
        'currentTeam' => (object) ['id' => 45],
        'created_at' => now()->startOfSecond(),
    ];

    $providerAccount = (new SocialiteUser())->map([
        'id' => 'oauth-uid-9',
        'nickname' => 'ada_dev',
    ]);

    $event = new NewOAuthRegistration($user, 'google', $providerAccount);

    (new SyncNewOAuthUserToCrm())->handle($event);

    Bus::assertDispatched(SyncNewUserToCrm::class);
});
