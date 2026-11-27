<?php

declare(strict_types=1);

use Liberu\Foundation\Settings\Contracts\SettingDefinition;
use Liberu\Foundation\Settings\Services\ScopedSettings;
use Liberu\Foundation\Settings\Settings\SiteSettings;
use Liberu\Foundation\Settings\SettingsServiceProvider;

it('covers SettingsServiceProvider, SiteSettings group, and ScopedSettings plain, secret, validation, and fallback paths', function (): void {
    $provider = $this->app->register(SettingsServiceProvider::class, force: true);
    $provider->boot();

    expect(SiteSettings::group())->toBe('site');

    $plainDef = new class() implements SettingDefinition
    {
        public function key(): string
        {
            return 'invoice.prefix';
        }

        public function validate(mixed $value): bool
        {
            return is_string($value) && $value !== '';
        }

        public function secret(): bool
        {
            return false;
        }
    };

    $secretDef = new class() implements SettingDefinition
    {
        public function key(): string
        {
            return 'smtp.password';
        }

        public function validate(mixed $value): bool
        {
            return is_string($value) && strlen($value) >= 4;
        }

        public function secret(): bool
        {
            return true;
        }
    };

    $service = new ScopedSettings();

    // Validation failure throws InvalidArgumentException
    expect(fn () => $service->put($plainDef, 'team', '10', ''))
        ->toThrow(InvalidArgumentException::class, 'Invalid setting [invoice.prefix].');

    // Store plain setting at global and team scopes
    $service->put($plainDef, 'global', 'default', 'INV');
    $service->put($plainDef, 'team', '10', 'ACME');

    // Store secret setting (encrypted at rest)
    $service->put($secretDef, 'team', '10', 's3cr3t-pass');

    // Resolve skips null scope IDs and returns first matching scope
    expect($service->resolve('invoice.prefix', ['user' => null, 'team' => '10', 'global' => 'default'], 'FALLBACK'))
        ->toBe('ACME')
        ->and($service->resolve('invoice.prefix', ['team' => '999', 'global' => 'default'], 'FALLBACK'))
        ->toBe('INV')
        ->and($service->resolve('smtp.password', ['team' => '10'], null))
        ->toBe('s3cr3t-pass')
        ->and($service->resolve('missing.key', ['team' => '10'], 'DEFAULT'))
        ->toBe('DEFAULT');
});
