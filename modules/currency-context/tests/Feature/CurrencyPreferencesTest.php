<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\Currency\Services\CurrencyPreferenceResolver;

beforeEach(function () {
    $this->artisan('migrate', ['--database' => 'testing'])->assertSuccessful();
});

it('chooses the first matching scope without crossing owner boundaries', function () {
    DB::table('currency_preferences')->insert([
        ['scope_type' => 'user', 'scope_id' => '1', 'currency' => 'eur'],
        ['scope_type' => 'team', 'scope_id' => '1', 'currency' => 'jpy'],
        ['scope_type' => 'team', 'scope_id' => '2', 'currency' => 'gbp'],
    ]);
    $resolver = new CurrencyPreferenceResolver();

    expect($resolver->resolve(['user' => 1, 'team' => 2], 'usd'))->toBe('EUR')
        ->and($resolver->resolve(['user' => null, 'team' => 2], 'usd'))->toBe('GBP')
        ->and($resolver->resolve(['user' => 2, 'team' => 1], 'usd'))->toBe('JPY')
        ->and($resolver->resolve(['user' => 3, 'team' => 3], 'usd'))->toBe('USD')
        ->and($resolver->resolve([], 'eur'))->toBe('EUR');
});

it('installs and rolls back currency preference and exchange-rate tables', function () {
    expect(Schema::hasColumns('currency_preferences', ['scope_type', 'scope_id', 'currency']))->toBeTrue()
        ->and(Schema::hasColumns('exchange_rate_snapshots', ['base_currency', 'quote_currency', 'rate', 'source', 'effective_at', 'inverted']))->toBeTrue();

    $this->artisan('migrate:rollback', ['--database' => 'testing'])->assertSuccessful();

    expect(Schema::hasTable('currency_preferences'))->toBeFalse()
        ->and(Schema::hasTable('exchange_rate_snapshots'))->toBeFalse();
});
