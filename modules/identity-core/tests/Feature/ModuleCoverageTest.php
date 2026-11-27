<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Foundation\Identity\Contracts\InvitationValidator;
use Liberu\Foundation\Identity\Contracts\RegistrationPolicy;
use Liberu\Foundation\Identity\Events\IdentityEvent;
use Liberu\Foundation\Identity\IdentityServiceProvider;
use Liberu\Foundation\Identity\Jobs\SyncNewUserToCrm;
use Liberu\Foundation\Identity\Listeners\EmitAuthenticationEvent;
use Liberu\Foundation\Identity\Support\ConfiguredRegistrationPolicy;
use Liberu\Foundation\Identity\Support\IdentifierNormalizer;
use Liberu\Foundation\Identity\Support\RejectingInvitationValidator;

it('boots identity service provider and covers policies, normalizer, validator, and identity events', function (): void {
    config()->set('identity.registration', 'invitation');

    $provider = $this->app->register(IdentityServiceProvider::class, force: true);
    $provider->register();
    $provider->boot();

    $policy = app(RegistrationPolicy::class);
    expect($policy)->toBeInstanceOf(ConfiguredRegistrationPolicy::class)
        ->and($policy->permitsSelfRegistration())->toBeTrue()
        ->and($policy->requiresInvitation())->toBeTrue()
        ->and((new ConfiguredRegistrationPolicy('open'))->permitsSelfRegistration())->toBeTrue()
        ->and((new ConfiguredRegistrationPolicy('open'))->requiresInvitation())->toBeFalse()
        ->and((new ConfiguredRegistrationPolicy('closed'))->permitsSelfRegistration())->toBeFalse()
        ->and((new IdentifierNormalizer())->email('  Alice@Example.COM '))->toBe('alice@example.com')
        ->and(app(InvitationValidator::class))->toBeInstanceOf(RejectingInvitationValidator::class)
        ->and(app(InvitationValidator::class)->valid('alice@example.com', 'tok'))->toBeFalse();

    $fixed = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $evDefault = new IdentityEvent('identity.custom', 1, ['k' => 'v']);
    $evFixed = new IdentityEvent('identity.custom', 1, [], '2.0', $fixed);
    expect($evDefault->occurredAt->getTimezone()->getName())->toBe('UTC')
        ->and($evFixed->occurredAt)->toBe($fixed);
});

it('emits IdentityEvent on Login, Failed, Logout and ignores unknown events', function (): void {
    Event::fake([IdentityEvent::class]);

    $user = User::factory()->create();
    $listener = new EmitAuthenticationEvent();

    $listener->handle(new Login('web', $user, false));
    $listener->handle(new Failed('web', $user, ['email' => $user->email]));
    $listener->handle(new Logout('web', $user));
    $listener->handle(new stdClass());

    $dispatched = [];
    Event::assertDispatched(IdentityEvent::class, function (IdentityEvent $e) use (&$dispatched): bool {
        $dispatched[] = $e->name;

        return true;
    });

    expect($dispatched)->toBe([
        'identity.login.succeeded',
        'identity.login.failed',
        'identity.logout.completed',
    ]);
});

it('handles SyncNewUserToCrm job for unconfigured, missing user, success, backoff, and failed paths', function (): void {
    $user = User::factory()->create(['name' => 'CRM Lead', 'email' => 'lead@example.com']);
    $job = new SyncNewUserToCrm($user->id, 'registration', ['ip' => '127.0.0.1'], ['plan' => 'pro']);

    expect($job->backoff())->toBe([10, 30, 60, 300, 900]);

    // Unconfigured no-op
    config()->set('services.crm.base_url', null);
    config()->set('services.crm.token', null);
    Http::fake();
    $job->handle();
    Http::assertNothingSent();

    // Missing user no-op
    config()->set('services.crm.base_url', 'https://crm.example.test');
    config()->set('services.crm.token', 'secret-token');
    (new SyncNewUserToCrm(999999, 'registration'))->handle();
    Http::assertNothingSent();

    // Configured and existing user posts lead
    Http::fake([
        'https://crm.example.test/api/v1/crm/lead-capture' => Http::response(['ok' => true], 201),
    ]);
    $job->handle();
    Http::assertSentCount(1);

    // Failed callback logs error
    Log::shouldReceive('error')->once();
    $job->failed(new RuntimeException('CRM unreachable'));
});
