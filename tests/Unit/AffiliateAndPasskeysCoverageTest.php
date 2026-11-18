<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Affiliate\Core\Services\CommissionCalculator;
use Liberu\Passkeys\Services\WebAuthnCeremonyService;

it('covers Affiliate CommissionCalculator across zero, negative, and positive amounts', function (): void {
    $calculator = new CommissionCalculator();

    expect($calculator->calculateMinor(0, 1000, 50))->toBe(0)
        ->and($calculator->calculateMinor(-100, 1000, 50))->toBe(0)
        ->and($calculator->calculateMinor(10000, 1500))->toBe(1500)
        ->and($calculator->calculateMinor(10000, 1500, 250))->toBe(1750);
});

it('covers Passkeys WebAuthnCeremonyService registration and assertion options', function (): void {
    $actor = new class() implements Authenticatable
    {
        public string $email = 'passkey@example.test';

        public string $name = 'Passkey User';

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): mixed
        {
            return 42;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };

    $service = new WebAuthnCeremonyService();
    $registration = $service->beginRegistration($actor, 'example.test', 'Example App');
    $assertion = $service->beginAssertion('example.test', $actor);

    expect($registration)->toHaveKeys(['challenge', 'rp', 'user', 'pubKeyCredParams', 'authenticatorSelection', 'timeout'])
        ->and($registration['rp'])->toBe(['id' => 'example.test', 'name' => 'Example App'])
        ->and($registration['user']['name'])->toBe('passkey@example.test')
        ->and($registration['user']['displayName'])->toBe('Passkey User')
        ->and($registration['timeout'])->toBe(120000)
        ->and($assertion)->toHaveKeys(['challenge', 'rpId', 'userVerification', 'timeout'])
        ->and($assertion['rpId'])->toBe('example.test')
        ->and($assertion['userVerification'])->toBe('required')
        ->and($assertion['timeout'])->toBe(120000);
});
