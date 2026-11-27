<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Liberu\Foundation\Currency\Contracts\ExchangeRateProvider;
use Liberu\Foundation\Currency\CurrencyServiceProvider;
use Liberu\Foundation\Currency\Enums\CurrencyRole;
use Liberu\Foundation\Currency\Exceptions\CurrencyMismatch;
use Liberu\Foundation\Currency\Exceptions\UnknownCurrency;
use Liberu\Foundation\Currency\Services\CurrencyContext;
use Liberu\Foundation\Currency\Services\CurrencyConverter;
use Liberu\Foundation\Currency\Services\CurrencyPreferenceResolver;
use Liberu\Foundation\Currency\Services\CurrencyRegistry;
use Liberu\Foundation\Currency\Services\MoneyFormatter;
use Liberu\Foundation\Currency\Services\OpenExchangeRateApiProvider;
use Liberu\Foundation\Currency\ValueObjects\Currency;
use Liberu\Foundation\Currency\ValueObjects\ExchangeRate;
use Liberu\Foundation\Currency\ValueObjects\Money;

it('registers currency service provider, context, registry, money, and exchange rate value objects', function (): void {
    config()->set('currency.currencies', [
        'USD' => ['minor_units' => 2, 'symbol' => '$'],
        'EUR' => ['minor_units' => 2, 'symbol' => '€'],
        'JPY' => ['minor_units' => 0, 'symbol' => '¥'],
    ]);
    config()->set('currency.base', 'USD');
    config()->set('currency.display', 'EUR');

    $provider = $this->app->register(CurrencyServiceProvider::class, force: true);
    $provider->register();
    $provider->boot();

    $context = app(CurrencyContext::class);
    expect($context->for(CurrencyRole::Base)->code)->toBe('USD')
        ->and($context->for(CurrencyRole::Display)->code)->toBe('EUR')
        ->and($context->for(CurrencyRole::Settlement)->code)->toBe('USD');

    // Display null falls back to base
    config()->set('currency.display', null);
    app()->forgetScopedInstances();
    $fallbackContext = app(CurrencyContext::class);
    expect($fallbackContext->for(CurrencyRole::Display)->code)->toBe('USD');

    $registry = app(CurrencyRegistry::class);
    $usd = $registry->get('usd');
    $eur = $registry->get('EUR');
    $jpy = $registry->get('JPY');

    expect(fn () => $registry->get('ZZZ'))->toThrow(UnknownCurrency::class);
    expect(fn () => new Currency('US', 2, '$'))->toThrow(UnknownCurrency::class);
    expect(fn () => new Currency('USD', 7, '$'))->toThrow(UnknownCurrency::class);

    $m1 = new Money(1250, $usd);
    $m2 = new Money(250, $usd);
    $mNeg = new Money(-50, $usd);
    $mJpy = new Money(500, $jpy);
    $mEur = new Money(100, $eur);

    expect($m1->add($m2)->minorAmount)->toBe(1500)
        ->and($m1->subtract($m2)->minorAmount)->toBe(1000)
        ->and($m1->equals(new Money(1250, $usd)))->toBeTrue()
        ->and($m1->equals($m2))->toBeFalse()
        ->and($m1->decimal())->toBe('12.50')
        ->and($mNeg->decimal())->toBe('-0.50')
        ->and($mJpy->decimal())->toBe('500');

    expect(fn () => $m1->add($mEur))->toThrow(CurrencyMismatch::class);
    expect(fn () => $m1->subtract($mEur))->toThrow(CurrencyMismatch::class);

    $now = new DateTimeImmutable('2026-01-01T12:00:00Z');
    $rate = new ExchangeRate($usd, $eur, '0.92', 'test', 'market', $now, 6);
    expect($rate->isStale(new DateTimeImmutable('2026-01-01T12:01:00Z'), 30))->toBeTrue()
        ->and($rate->isStale(new DateTimeImmutable('2026-01-01T12:00:10Z'), 30))->toBeFalse();

    expect(fn () => new ExchangeRate($usd, $usd, '1.0', 'test', 'market', $now, 6))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new ExchangeRate($usd, $eur, '0.00', 'test', 'market', $now, 6))
        ->toThrow(InvalidArgumentException::class);
});

it('covers MoneyFormatter, CurrencyPreferenceResolver, and CurrencyConverter branches', function (): void {
    $usd = new Currency('USD', 2, '$');
    $eur = new Currency('EUR', 2, '€');
    $money = new Money(1999, $usd);

    $defaultFormatter = new MoneyFormatter();
    expect($defaultFormatter->format($money, 'en_US'))->toContain('19.99');

    $failingFormatter = new MoneyFormatter(fn (string $locale): object => new class()
    {
        public function formatCurrency(float $amount, string $currency): false
        {
            return false;
        }
    });
    expect($failingFormatter->format($money, 'en_US'))->toBe('USD 19.99');

    DB::table('currency_preferences')->insert([
        'scope_type' => 'team',
        'scope_id' => '42',
        'currency' => 'eur',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $prefResolver = new CurrencyPreferenceResolver();
    expect($prefResolver->resolve(['user' => null, 'team' => 42], 'usd'))->toBe('EUR')
        ->and($prefResolver->resolve(['user' => 999], 'gbp'))->toBe('GBP');

    $registry = new CurrencyRegistry([
        'USD' => ['minor_units' => 2, 'symbol' => '$'],
        'EUR' => ['minor_units' => 2, 'symbol' => '€'],
        'GBP' => ['minor_units' => 2, 'symbol' => '£'],
    ]);

    $directProvider = new class() implements ExchangeRateProvider
    {
        public ?ExchangeRate $next = null;

        public function rate(Currency $base, Currency $quote, DateTimeImmutable $effectiveAt): ?ExchangeRate
        {
            return $this->next;
        }
    };

    $converter = new CurrencyConverter($registry, $directProvider);

    // Same currency short-circuit
    expect($converter->convertAmount(100.0, 'usd', 'USD'))->toBe(100.0);

    // Unknown currency returns null
    expect($converter->convertAmount(100.0, 'USD', 'ZZZ'))->toBeNull();

    // Missing rate returns null
    $directProvider->next = null;
    expect($converter->convertAmount(100.0, 'USD', 'EUR'))->toBeNull();

    // Direct rate
    $directProvider->next = new ExchangeRate($usd, $eur, '0.80', 'stub', 'market', new DateTimeImmutable(), 6, false);
    expect($converter->convertAmount(100.0, 'USD', 'EUR'))->toBe(80.0);

    // Inverted rate
    $directProvider->next = new ExchangeRate($usd, $eur, '2.0', 'stub', 'market', new DateTimeImmutable(), 6, true);
    expect($converter->convertAmount(100.0, 'USD', 'EUR'))->toBe(50.0);
});

it('covers OpenExchangeRateApiProvider disabled, success, cache hit, fallback, invalid, and exception paths', function (): void {
    Cache::flush();

    $usd = new Currency('USD', 2, '$');
    $eur = new Currency('EUR', 2, '€');
    $gbp = new Currency('GBP', 2, '£');
    $now = new DateTimeImmutable('2026-02-01T00:00:00Z');
    $provider = new OpenExchangeRateApiProvider();

    // Disabled returns null
    config()->set('currency.exchange_rates_enabled', false);
    expect($provider->rate($usd, $eur, $now))->toBeNull();

    // Primary success + cache hit
    config()->set('currency.exchange_rates_enabled', true);
    config()->set('currency.exchange_rate_api_url', 'https://primary.example/v6/latest/{base}');
    config()->set('currency.exchange_rate_api_fallback_url', 'https://fallback.example/v6/latest/{base}');
    config()->set('currency.stale_after_seconds', 3600);

    Http::fake([
        'https://primary.example/v6/latest/USD' => Http::response([
            'result' => 'success',
            'time_last_update_unix' => 1700000000,
            'rates' => ['EUR' => 0.91, 'BAD' => -1],
        ], 200),
    ]);

    $rate = $provider->rate($usd, $eur, $now);
    expect($rate)->toBeInstanceOf(ExchangeRate::class)
        ->and($rate->rate)->toBe('0.91')
        ->and($rate->source)->toBe('primary.example')
        ->and($rate->effectiveAt->getTimestamp())->toBe(1700000000);

    // Cache hit + unlisted quote returns null
    expect($provider->rate($usd, $gbp, $now))->toBeNull();
    Http::assertSentCount(1);

    // Fallback endpoint when primary fails (non-200) and no time_last_update_unix
    Cache::flush();
    Http::fake([
        'https://primary.example/v6/latest/EUR' => Http::response(['result' => 'error'], 500),
        'https://fallback.example/v6/latest/EUR' => Http::response([
            'result' => 'success',
            'rates' => ['USD' => 1.1],
        ], 200),
    ]);

    $fallbackRate = $provider->rate($eur, $usd, $now);
    expect($fallbackRate)->toBeInstanceOf(ExchangeRate::class)
        ->and($fallbackRate->rate)->toBe('1.1')
        ->and($fallbackRate->effectiveAt)->toBe($now);

    // Exception thrown during HTTP fetch returns null
    Cache::flush();
    config()->set('currency.exchange_rate_api_fallback_url', '');
    Http::fake([
        'https://primary.example/v6/latest/GBP' => fn () => throw new RuntimeException('network down'),
    ]);

    expect($provider->rate($gbp, $usd, $now))->toBeNull();
});
