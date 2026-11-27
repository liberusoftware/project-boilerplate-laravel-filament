<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Liberu\Foundation\Identity\Contracts\InvitationValidator;
use Liberu\Foundation\Identity\Contracts\RegistrationPolicy;
use Liberu\Foundation\Identity\Events\IdentityEvent;
use Liberu\Foundation\Identity\Jobs\SyncNewUserToCrm;
use Liberu\Foundation\Identity\Listeners\EmitAuthenticationEvent;
use Liberu\Foundation\Identity\Support\ConfiguredRegistrationPolicy;
use Liberu\Foundation\Identity\Support\IdentifierNormalizer;
use Liberu\Foundation\Identity\Support\RejectingInvitationValidator;

it('binds registration policy and invitation validator in the container', function () {
    expect(app(RegistrationPolicy::class))->toBeInstanceOf(ConfiguredRegistrationPolicy::class)
        ->and(app(InvitationValidator::class))->toBeInstanceOf(RejectingInvitationValidator::class);
});

it('evaluates configured registration policy modes', function () {
    $open = new ConfiguredRegistrationPolicy('open');
    $invitation = new ConfiguredRegistrationPolicy('invitation');
    $closed = new ConfiguredRegistrationPolicy('closed');

    expect($open->permitsSelfRegistration())->toBeTrue()
        ->and($open->requiresInvitation())->toBeFalse()
        ->and($invitation->permitsSelfRegistration())->toBeTrue()
        ->and($invitation->requiresInvitation())->toBeTrue()
        ->and($closed->permitsSelfRegistration())->toBeFalse()
        ->and($closed->requiresInvitation())->toBeFalse();
});

it('normalizes email addresses and rejects invitations by default', function () {
    $normalizer = new IdentifierNormalizer();
    $validator = new RejectingInvitationValidator();

    expect($normalizer->email('  Ada@Example.TEST  '))->toBe('ada@example.test')
        ->and($validator->valid('ada@example.test', 'secret-token'))->toBeFalse();
});

it('emits identity events for auth lifecycle events and ignores unknown objects', function () {
    Event::fake([IdentityEvent::class]);

    $listener = new EmitAuthenticationEvent();
    $user = new GenericUser(['id' => 7]);

    $listener->handle(new Login('web', $user, false));
    $listener->handle(new Failed('web', $user, ['email' => 'ada@example.test']));
    $listener->handle(new Logout('web', $user));
    $listener->handle(new stdClass());

    Event::assertDispatched(IdentityEvent::class, 3);
    Event::assertDispatched(IdentityEvent::class, fn (IdentityEvent $e) => $e->name === 'identity.login.succeeded' && $e->actorId === 7 && $e->context === ['guard' => 'web']);
    Event::assertDispatched(IdentityEvent::class, fn (IdentityEvent $e) => $e->name === 'identity.login.failed' && $e->actorId === 7);
    Event::assertDispatched(IdentityEvent::class, fn (IdentityEvent $e) => $e->name === 'identity.logout.completed' && $e->actorId === 7);
});

it('syncs new users to CRM when configured and handles missing config, missing users, and failures', function () {
    $stubModel = new class()
    {
        public static ?object $record = null;

        public static function find(int $id): ?object
        {
            return self::$record !== null && self::$record->id === $id ? self::$record : null;
        }
    };

    $job = new SyncNewUserToCrm(42, 'email', ['locale' => 'en'], ['ip' => '127.0.0.1']);
    expect($job->backoff())->toBe([10, 30, 60, 300, 900]);

    // No-op when CRM config is not set.
    Http::fake();
    config()->set('services.crm.base_url', null);
    config()->set('services.crm.token', null);
    $job->handle();
    Http::assertNothingSent();

    // No-op when user cannot be found.
    config()->set('services.crm.base_url', 'https://crm.example.test');
    config()->set('services.crm.token', 'crm-secret-token');
    config()->set('services.crm.external_key_prefix', 'billing-');
    config()->set('auth.providers.users.model', $stubModel::class);
    $stubModel::$record = null;
    $job->handle();
    Http::assertNothingSent();

    // Posts lead payload when configured and user exists.
    $stubModel::$record = (object) [
        'id' => 42,
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
    ];
    Http::fake([
        'https://crm.example.test/api/v1/crm/lead-capture' => Http::response(['ok' => true], 201),
    ]);

    $job->handle();

    Http::assertSent(function (HttpClientRequest $request): bool {
        return $request->url() === 'https://crm.example.test/api/v1/crm/lead-capture'
            && $request->hasHeader('Authorization', 'Bearer crm-secret-token')
            && $request['external_key'] === 'billing-42'
            && $request['channel'] === 'api'
            && $request['status'] === 'new'
            && $request['name'] === 'Ada Lovelace'
            && $request['email'] === 'ada@example.test'
            && $request['source'] === 'email'
            && $request['source_metadata'] === ['locale' => 'en']
            && $request['payload'] === ['ip' => '127.0.0.1'];
    });

    // Logs error when job fails.
    Log::shouldReceive('error')
        ->once()
        ->with('Failed to sync new user to CRM', [
            'user_id' => 42,
            'source' => 'email',
            'error' => 'CRM unavailable',
        ]);

    $job->failed(new RuntimeException('CRM unavailable'));
});
