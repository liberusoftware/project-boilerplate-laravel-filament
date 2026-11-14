<?php

use Liberu\Foundation\Currency\Contracts\ExchangeRateProvider;
use Liberu\Foundation\Currency\Services\CurrencyConverter;
use Liberu\Foundation\Currency\Services\CurrencyRegistry;
use Liberu\Foundation\Currency\Services\OpenExchangeRateApiProvider;
use Liberu\Foundation\Currency\ValueObjects\Currency;
use Liberu\Foundation\Currency\ValueObjects\ExchangeRate;

it('registers the converter and its opt-in rate provider', function () {
    expect(app(ExchangeRateProvider::class))->toBeInstanceOf(OpenExchangeRateApiProvider::class)
        ->and(app(CurrencyConverter::class))->toBeInstanceOf(CurrencyConverter::class)
        ->and(app(CurrencyConverter::class))->toBe(app(CurrencyConverter::class));
});

it('preserves amounts between equivalent currency codes without requesting rates', function () {
    $rates = Mockery::mock(ExchangeRateProvider::class);
    $rates->shouldNotReceive('rate');
    $converter = new CurrencyConverter(app(CurrencyRegistry::class), $rates);

    expect($converter->convertAmount(123.45, 'usd', 'USD'))->toBe(123.45);
});

it('returns no conversion for an unknown currency', function (string $from, string $to) {
    $rates = Mockery::mock(ExchangeRateProvider::class);
    $rates->shouldNotReceive('rate');

    expect((new CurrencyConverter(app(CurrencyRegistry::class), $rates))->convertAmount(100.0, $from, $to))->toBeNull();
})->with(['unknown source' => ['XYZ', 'EUR'], 'unknown target' => ['USD', 'XYZ']]);

it('returns no conversion when the provider has no matching rate', function () {
    $rates = Mockery::mock(ExchangeRateProvider::class);
    $rates->shouldReceive('rate')->once()->andReturnNull();

    expect((new CurrencyConverter(app(CurrencyRegistry::class), $rates))->convertAmount(100.0, 'USD', 'EUR'))->toBeNull();
});

it('applies direct and inverted rates to display amounts', function (bool $inverted, float $expected) {
    $registry = app(CurrencyRegistry::class);
    $rates = Mockery::mock(ExchangeRateProvider::class);
    $rates->shouldReceive('rate')->once()->withArgs(
        fn (Currency $base, Currency $quote, DateTimeImmutable $at): bool => $base->code === 'USD' && $quote->code === 'EUR',
    )->andReturn(new ExchangeRate($registry->get('USD'), $registry->get('EUR'), '1.25', 'test-feed', 'spot', new DateTimeImmutable(), 6, $inverted));

    expect((new CurrencyConverter($registry, $rates))->convertAmount(100.0, 'usd', 'eur'))->toBe($expected);
})->with(['direct quote' => [false, 125.0], 'inverted quote' => [true, 80.0]]);
