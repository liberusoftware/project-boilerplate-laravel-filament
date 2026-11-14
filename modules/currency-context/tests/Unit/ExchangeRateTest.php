<?php

use Liberu\Foundation\Currency\ValueObjects\Currency;
use Liberu\Foundation\Currency\ValueObjects\ExchangeRate;

it('preserves the rate and its provenance without numeric conversion', function () {
    $base = new Currency('USD', 2, '$');
    $quote = new Currency('EUR', 2, '€');
    $effectiveAt = new DateTimeImmutable('2026-11-01T12:00:00+00:00');
    $rate = new ExchangeRate($base, $quote, '0.912345678901', 'test-feed', 'spot', $effectiveAt, 12, true);

    expect($rate->base)->toBe($base)
        ->and($rate->quote)->toBe($quote)
        ->and($rate->rate)->toBe('0.912345678901')
        ->and($rate->source)->toBe('test-feed')
        ->and($rate->type)->toBe('spot')
        ->and($rate->effectiveAt)->toBe($effectiveAt)
        ->and($rate->precision)->toBe(12)
        ->and($rate->inverted)->toBeTrue()
        ->and($rate->isStale($effectiveAt->modify('+3600 seconds'), 3600))->toBeFalse()
        ->and($rate->isStale($effectiveAt->modify('+3601 seconds'), 3600))->toBeTrue();
});

it('rejects rates that are not positive decimal values', function (string $rate) {
    expect(fn () => new ExchangeRate(new Currency('USD', 2, '$'), new Currency('EUR', 2, '€'), $rate, 'test-feed', 'spot', new DateTimeImmutable(), 12))
        ->toThrow(InvalidArgumentException::class, 'An exchange rate must be positive and relate distinct currencies.');
})->with(['0', '0.000', '-1', '1e2', 'invalid', '1.2.3']);

it('rejects a rate between the same currency', function () {
    $usd = new Currency('USD', 2, '$');

    expect(fn () => new ExchangeRate($usd, $usd, '1', 'test-feed', 'spot', new DateTimeImmutable(), 12))
        ->toThrow(InvalidArgumentException::class);
});
