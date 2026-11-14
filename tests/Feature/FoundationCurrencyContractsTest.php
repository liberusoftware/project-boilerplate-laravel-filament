<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Liberu\Foundation\Currency\Contracts\ExchangeRateProvider;
use Liberu\Foundation\Currency\Enums\CurrencyRole;
use Liberu\Foundation\Currency\Exceptions\CurrencyMismatch;
use Liberu\Foundation\Currency\Services\CurrencyContext;
use Liberu\Foundation\Currency\Services\CurrencyConverter;
use Liberu\Foundation\Currency\Services\CurrencyRegistry;
use Liberu\Foundation\Currency\Services\MoneyFormatter;
use Liberu\Foundation\Currency\ValueObjects\ExchangeRate;
use Liberu\Foundation\Currency\ValueObjects\Money;

beforeEach(function (): void {
    config([
        'currency.exchange_rates_enabled' => true,
        'currency.exchange_rate_api_url' => 'https://host-rates.example.test/{base}',
        'currency.stale_after_seconds' => 600,
    ]);
    Cache::flush();
    Http::preventStrayRequests();
});

it('resolves currency preferences per scope without leaking the previous display currency', function (): void {
    config(['currency.base' => 'USD', 'currency.display' => null]);
    $first = app(CurrencyContext::class);
    expect($first->for(CurrencyRole::Display)->code)->toBe('USD')
        ->and(app(CurrencyContext::class))->toBe($first);

    app()->forgetScopedInstances();
    config(['currency.base' => 'GBP', 'currency.display' => 'EUR']);
    $second = app(CurrencyContext::class);
    expect($second)->not->toBe($first)
        ->and($second->for(CurrencyRole::Base)->code)->toBe('GBP')
        ->and($second->for(CurrencyRole::Display)->code)->toBe('EUR')
        ->and($first->for(CurrencyRole::Display)->code)->toBe('USD');
});

it('converts through the installed rate provider and shares cached quotes until expiry', function (): void {
    $this->travelTo(now()->startOfSecond());
    Http::fakeSequence('https://host-rates.example.test/USD')
        ->push(['result' => 'success', 'rates' => ['EUR' => 0.9], 'time_last_update_unix' => 1700000000])
        ->push(['result' => 'success', 'rates' => ['EUR' => 0.95]]);
    $converter = app(CurrencyConverter::class);
    expect(app(CurrencyConverter::class))->toBe($converter)
        ->and($converter->convertAmount(100, 'usd', 'eur'))->toBe(90.0);
    $registry = app(CurrencyRegistry::class);
    $at = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $quote = app(ExchangeRateProvider::class)->rate($registry->get('USD'), $registry->get('EUR'), $at);
    expect($quote->effectiveAt->getTimestamp())->toBe(1700000000)
        ->and($quote->source)->toBe('open.er-api.com')
        ->and($quote->rate)->toBe('0.9');
    $this->travel(599)->seconds();
    expect($converter->convertAmount(100, 'USD', 'EUR'))->toBe(90.0);
    Http::assertSentCount(1);
    $this->travel(2)->seconds();
    $refreshed = app(ExchangeRateProvider::class)->rate($registry->get('USD'), $registry->get('EUR'), $at);
    expect($refreshed->rate)->toBe('0.95')->and($refreshed->effectiveAt)->toBe($at);
    Http::assertSentCount(2);
});

it('does not request external rates for equivalent unknown or disabled conversions', function (): void {
    Http::fake();
    $converter = app(CurrencyConverter::class);
    expect($converter->convertAmount(123.45, 'usd', 'USD'))->toBe(123.45)
        ->and($converter->convertAmount(100, 'XYZ', 'EUR'))->toBeNull()
        ->and($converter->convertAmount(100, 'USD', 'XYZ'))->toBeNull();
    config(['currency.exchange_rates_enabled' => false]);
    expect($converter->convertAmount(100, 'USD', 'EUR'))->toBeNull();
    Http::assertNothingSent();
});

it('omits conversions when the upstream quote is missing or invalid', function (array $rates): void {
    Http::fake(['https://host-rates.example.test/USD' => Http::response(['result' => 'success', 'rates' => $rates])]);
    expect(app(CurrencyConverter::class)->convertAmount(100, 'USD', 'EUR'))->toBeNull();
    Http::assertSentCount(1);
})->with([
    'missing currency' => [['GBP' => 0.8]],
    'nonnumeric quote' => [['EUR' => 'invalid']],
    'zero quote' => [['EUR' => 0]],
    'negative quote' => [['EUR' => -1]],
]);

it('briefly caches failed feeds and permits recovery without changing the converter', function (int $status, string $result): void {
    $this->travelTo(now()->startOfSecond());
    Http::fakeSequence('https://host-rates.example.test/USD')
        ->push(['result' => $result, 'rates' => ['EUR' => 0.9]], $status)
        ->push(['result' => 'success', 'rates' => ['EUR' => 0.95]]);
    $converter = app(CurrencyConverter::class);
    expect($converter->convertAmount(100, 'USD', 'EUR'))->toBeNull()
        ->and($converter->convertAmount(100, 'USD', 'EUR'))->toBeNull();
    Http::assertSentCount(1);
    $this->travel(61)->seconds();
    expect($converter->convertAmount(100, 'USD', 'EUR'))->toBe(95.0);
    Http::assertSentCount(2);
})->with(['HTTP failure' => [503, 'success'], 'provider failure' => [200, 'error']]);

it('treats connection failure as unavailable conversion and suppresses repeated requests', function (): void {
    $attempts = 0;
    Http::fake(function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('private-feed-failure');
    });
    $converter = app(CurrencyConverter::class);
    expect($converter->convertAmount(100, 'USD', 'EUR'))->toBeNull()
        ->and($converter->convertAmount(100, 'USD', 'EUR'))->toBeNull()
        ->and($attempts)->toBe(1);
});

it('isolates cached quotes when the configured feed changes', function (): void {
    Http::fake([
        'https://host-rates.example.test/USD' => Http::response(['result' => 'success', 'rates' => ['EUR' => 0.9]]),
        'https://other-host-rates.example.test/USD' => Http::response(['result' => 'success', 'rates' => ['EUR' => 0.8]]),
    ]);
    $converter = app(CurrencyConverter::class);
    expect($converter->convertAmount(100, 'USD', 'EUR'))->toBe(90.0);
    config(['currency.exchange_rate_api_url' => 'https://other-host-rates.example.test/{base}']);
    expect($converter->convertAmount(100, 'USD', 'EUR'))->toBe(80.0);
    Http::assertSentCount(2);
});

it('honors an inverted quote supplied through the public rate contract', function (): void {
    $registry = app(CurrencyRegistry::class);
    $provider = Mockery::mock(ExchangeRateProvider::class);
    $provider->shouldReceive('rate')->once()->andReturn(new ExchangeRate(
        $registry->get('USD'), $registry->get('EUR'), '1.25', 'host-feed', 'spot', new DateTimeImmutable(), 6, true,
    ));
    app()->instance(ExchangeRateProvider::class, $provider);

    expect(app(CurrencyConverter::class)->convertAmount(100, 'USD', 'EUR'))->toBe(80.0);
});

it('falls back to an exact decimal when localized money formatting fails', function (): void {
    $formatter = Mockery::mock();
    $formatter->shouldReceive('formatCurrency')->once()->with(1234.56, 'USD')->andReturnFalse();
    $factory = function (string $locale) use ($formatter): object {
        expect($locale)->toBe('en_US');

        return $formatter;
    };
    $registry = app(CurrencyRegistry::class);
    expect((new MoneyFormatter($factory))->format(new Money(123456, $registry->get('USD')), 'en_US'))
        ->toBe('USD 1234.56')
        ->and((new Money(-123, $registry->get('JPY')))->decimal())->toBe('-123');
});

it('rejects mixed currency arithmetic without changing the original money', function (): void {
    $registry = app(CurrencyRegistry::class);
    $amount = new Money(100, $registry->get('USD'));
    $other = new Money(50, $registry->get('EUR'));

    expect(fn () => $amount->add($other))->toThrow(CurrencyMismatch::class, 'Cannot combine USD and EUR.')
        ->and(fn () => $amount->subtract($other))->toThrow(CurrencyMismatch::class, 'Cannot combine USD and EUR.')
        ->and($amount->decimal())->toBe('1.00')
        ->and($other->decimal())->toBe('0.50');
});
