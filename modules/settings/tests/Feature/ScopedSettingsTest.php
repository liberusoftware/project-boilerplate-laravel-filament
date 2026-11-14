<?php

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Liberu\Foundation\Settings\Contracts\SettingDefinition;
use Liberu\Foundation\Settings\Services\ScopedSettings;

function scopedDefinition(string $key, bool $secret = false): SettingDefinition
{
    return new class($key, $secret) implements SettingDefinition
    {
        public function __construct(private string $name, private bool $encrypted) {}

        public function key(): string
        {
            return $this->name;
        }

        public function validate(mixed $value): bool
        {
            return is_array($value);
        }

        public function secret(): bool
        {
            return $this->encrypted;
        }
    };
}

it('resolves typed values using caller scope priority and skips absent scopes', function () {
    $settings = app(ScopedSettings::class);
    $definition = scopedDefinition('notifications');
    $settings->put($definition, 'site', '1', ['enabled' => true, 'limit' => 10]);
    $settings->put($definition, 'user', '7', ['enabled' => false, 'limit' => 0]);

    expect($settings->resolve('notifications', ['team' => null, 'user' => 7, 'site' => 1]))
        ->toBe(['enabled' => false, 'limit' => 0])
        ->and($settings->resolve('notifications', ['user' => 8, 'site' => 1]))
        ->toBe(['enabled' => true, 'limit' => 10])
        ->and($settings->resolve('notifications', ['user' => 8], ['default' => true]))
        ->toBe(['default' => true]);
});

it('updates the matching key without leaking across scope types or identifiers', function () {
    $settings = app(ScopedSettings::class);
    $definition = scopedDefinition('display');
    $settings->put($definition, 'user', '1', ['mode' => 'dark']);
    $settings->put($definition, 'team', '1', ['mode' => 'team']);
    $settings->put($definition, 'user', '2', ['mode' => 'other']);
    $settings->put($definition, 'user', '1', ['mode' => 'light']);

    expect(DB::table('scoped_settings')->count())->toBe(3)
        ->and($settings->resolve('display', ['user' => 1]))->toBe(['mode' => 'light'])
        ->and($settings->resolve('display', ['team' => 1]))->toBe(['mode' => 'team'])
        ->and($settings->resolve('display', ['user' => 2]))->toBe(['mode' => 'other'])
        ->and($settings->resolve('unavailable', ['user' => 1]))->toBeNull();
});

it('encrypts secrets at rest and decrypts them only when resolving their scope', function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32))]);
    $settings = app(ScopedSettings::class);
    $value = ['token' => 'fixture-private-token', 'nested' => ['roles' => ['read']]];
    $settings->put(scopedDefinition('api', true), 'team', '4', $value);
    $row = DB::table('scoped_settings')->first();

    expect((bool) $row->secret)->toBeTrue()
        ->and($row->value)->not->toContain('fixture-private-token')
        ->and(json_decode(Crypt::decryptString($row->value), true))->toBe($value)
        ->and($settings->resolve('api', ['team' => 4]))->toBe($value)
        ->and($settings->resolve('api', ['team' => 5], 'missing'))->toBe('missing');
});

it('rejects invalid values before creating or changing any stored setting', function () {
    $settings = app(ScopedSettings::class);
    $definition = scopedDefinition('display');
    $settings->put($definition, 'user', '1', ['mode' => 'dark']);

    expect(fn () => $settings->put($definition, 'user', '1', 'invalid'))
        ->toThrow(InvalidArgumentException::class, 'Invalid setting [display].')
        ->and(DB::table('scoped_settings')->count())->toBe(1)
        ->and($settings->resolve('display', ['user' => 1]))->toBe(['mode' => 'dark']);
});

it('rejects values that cannot be encoded without writing partial data', function () {
    $value = [];
    $value['recursive'] = &$value;
    expect(fn () => app(ScopedSettings::class)->put(scopedDefinition('invalid'), 'site', '1', $value))
        ->toThrow(JsonException::class)
        ->and(DB::table('scoped_settings')->count())->toBe(0);
});

it('surfaces corrupt stored payloads instead of silently selecting a lower priority scope', function () {
    DB::table('scoped_settings')->insert([
        'scope_type' => 'user', 'scope_id' => '1', 'key' => 'display',
        'value' => 'invalid json', 'secret' => false,
    ]);

    expect(fn () => app(ScopedSettings::class)->resolve('display', ['user' => 1]))
        ->toThrow(JsonException::class);
});
