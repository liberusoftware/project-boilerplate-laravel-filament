<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Liberu\Foundation\Currency\Services\CurrencyRegistry;
use Liberu\Foundation\Currency\Services\OpenExchangeRateApiProvider;

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'currency.exchange_rates_enabled' => true,
        'currency.exchange_rate_api_url' => 'https://rates.example.test/{base}',
        'currency.stale_after_seconds' => 600,
    ]);
    Cache::flush();
    Http::preventStrayRequests();
});

it('keeps external exchange-rate requests disabled when not opted in', function () {
    config(['currency.exchange_rates_enabled' => false]);
    Http::fake();
    $registry = app(CurrencyRegistry::class);

    expect((new OpenExchangeRateApiProvider())->rate($registry->get('USD'), $registry->get('EUR'), new DateTimeImmutable()))->toBeNull();
    Http::assertNothingSent();
});

it('uses provider timestamps and caches quotes by their base URL', function () {
    Http::fake([
        'https://rates.example.test/USD' => Http::response([
            'result' => 'success', 'rates' => ['EUR' => 0.9, 'GBP' => 0.8], 'time_last_update_unix' => 1700000000,
        ]),
    ]);
    $registry = app(CurrencyRegistry::class);
    $provider = new OpenExchangeRateApiProvider();
    $effectiveAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    $euro = $provider->rate($registry->get('USD'), $registry->get('EUR'), $effectiveAt);
    $pound = $provider->rate($registry->get('USD'), $registry->get('GBP'), $effectiveAt);

    expect($euro->rate)->toBe('0.9')
        ->and($euro->base->code)->toBe('USD')
        ->and($euro->quote->code)->toBe('EUR')
        ->and($euro->source)->toBe('open.er-api.com')
        ->and($euro->type)->toBe('market')
        ->and($euro->precision)->toBe(6)
        ->and($euro->effectiveAt->getTimestamp())->toBe(1700000000)
        ->and($pound->rate)->toBe('0.8');
    Http::assertSentCount(1);

    config(['currency.exchange_rate_api_url' => 'https://other-rates.example.test/{base}']);
    Http::fake(['https://other-rates.example.test/USD' => Http::response(['result' => 'success', 'rates' => ['EUR' => 0.95]])]);
    $differentFeed = $provider->rate($registry->get('USD'), $registry->get('EUR'), $effectiveAt);
    expect($differentFeed->rate)->toBe('0.95')->and($differentFeed->effectiveAt)->toBe($effectiveAt);
});

it('refreshes cached quotes after the configured lifetime', function () {
    $this->travelTo(now()->startOfSecond());
    Http::fakeSequence('https://rates.example.test/USD')
        ->push(['result' => 'success', 'rates' => ['EUR' => 0.9]])
        ->push(['result' => 'success', 'rates' => ['EUR' => 0.95]]);
    $registry = app(CurrencyRegistry::class);
    $provider = new OpenExchangeRateApiProvider();
    $at = new DateTimeImmutable();

    expect($provider->rate($registry->get('USD'), $registry->get('EUR'), $at)->rate)->toBe('0.9');
    $this->travel(599)->seconds();
    expect($provider->rate($registry->get('USD'), $registry->get('EUR'), $at)->rate)->toBe('0.9');
    Http::assertSentCount(1);

    $this->travel(2)->seconds();
    expect($provider->rate($registry->get('USD'), $registry->get('EUR'), $at)->rate)->toBe('0.95');
    Http::assertSentCount(2);
});

it('returns no quote for missing or invalid upstream rates', function (array $rates) {
    Http::fake(['https://rates.example.test/USD' => Http::response(['result' => 'success', 'rates' => $rates])]);
    $registry = app(CurrencyRegistry::class);

    expect((new OpenExchangeRateApiProvider())->rate($registry->get('USD'), $registry->get('EUR'), new DateTimeImmutable()))->toBeNull();
    Http::assertSentCount(1);
})->with([
    'missing currency' => [['GBP' => 0.8]],
    'nonnumeric rate' => [['EUR' => 'invalid']],
    'zero rate' => [['EUR' => 0]],
    'negative rate' => [['EUR' => -1]],
]);

it('caches upstream failures briefly and permits recovery', function (int $status, string $result) {
    $this->travelTo(now()->startOfSecond());
    Http::fakeSequence('https://rates.example.test/USD')
        ->push(['result' => $result, 'rates' => ['EUR' => 0.9]], $status)
        ->push(['result' => 'success', 'rates' => ['EUR' => 0.95]]);
    $registry = app(CurrencyRegistry::class);
    $provider = new OpenExchangeRateApiProvider();
    $at = new DateTimeImmutable();

    expect($provider->rate($registry->get('USD'), $registry->get('EUR'), $at))->toBeNull()
        ->and($provider->rate($registry->get('USD'), $registry->get('EUR'), $at))->toBeNull();
    Http::assertSentCount(1);

    $this->travel(61)->seconds();
    expect($provider->rate($registry->get('USD'), $registry->get('EUR'), $at)->rate)->toBe('0.95');
    Http::assertSentCount(2);
})->with(['server failure' => [503, 'success'], 'provider failure' => [200, 'error']]);

it('treats connection failures as an unavailable rate without sending repeated requests', function () {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('unavailable');
    });
    $registry = app(CurrencyRegistry::class);
    $provider = new OpenExchangeRateApiProvider();
    $at = new DateTimeImmutable();

    expect($provider->rate($registry->get('USD'), $registry->get('EUR'), $at))->toBeNull()
        ->and($provider->rate($registry->get('USD'), $registry->get('EUR'), $at))->toBeNull()
        ->and($attempts)->toBe(1);
});
