<?php

use Liberu\Foundation\Currency\Enums\CurrencyRole;
use Liberu\Foundation\Currency\Exceptions\UnknownCurrency;
use Liberu\Foundation\Currency\Services\CurrencyContext;
use Liberu\Foundation\Currency\Services\CurrencyRegistry;
use Liberu\Foundation\Currency\Services\MoneyFormatter;
use Liberu\Foundation\Currency\ValueObjects\Currency;
use Liberu\Foundation\Currency\ValueObjects\Money;

it('normalizes registered currency codes and retains their metadata', function () {
    $registry = app(CurrencyRegistry::class);
    $usd = $registry->get('usd');

    expect($usd->code)->toBe('USD')
        ->and($usd->minorUnits)->toBe(2)
        ->and($usd->symbol)->toBe('$')
        ->and(app(CurrencyRegistry::class))->toBe($registry);
});

it('rejects unconfigured currencies', function () {
    expect(fn () => app(CurrencyRegistry::class)->get('xyz'))
        ->toThrow(UnknownCurrency::class, 'Currency [XYZ] is not configured.');
});

it('rejects invalid currency metadata', function (string $code, int $minorUnits) {
    expect(fn () => new Currency($code, $minorUnits, '$'))
        ->toThrow(UnknownCurrency::class, "Invalid currency metadata [{$code}].");
})->with([
    'short code' => ['US', 2],
    'long code' => ['USDD', 2],
    'numeric code' => ['12D', 2],
    'negative precision' => ['USD', -1],
    'excessive precision' => ['USD', 7],
]);

it('falls back to the base currency for roles without an override', function () {
    $base = new Currency('usd', 2, '$');
    $display = new Currency('eur', 2, '€');
    $context = new CurrencyContext(['base' => $base, 'display' => $display]);

    expect($context->for(CurrencyRole::Base))->toBe($base)
        ->and($context->for(CurrencyRole::Transaction))->toBe($base)
        ->and($context->for(CurrencyRole::Settlement))->toBe($base)
        ->and($context->for(CurrencyRole::Display))->toBe($display);
});

it('resolves display preferences per application scope', function (?string $display, string $expected) {
    config(['currency.base' => 'USD', 'currency.display' => $display]);
    $context = app(CurrencyContext::class);

    expect($context->for(CurrencyRole::Base)->code)->toBe('USD')
        ->and($context->for(CurrencyRole::Display)->code)->toBe($expected)
        ->and(app(CurrencyContext::class))->toBe($context);

    app()->forgetScopedInstances();
    config(['currency.base' => 'GBP', 'currency.display' => 'JPY']);
    $nextScope = app(CurrencyContext::class);

    expect($nextScope)->not->toBe($context)
        ->and($nextScope->for(CurrencyRole::Base)->code)->toBe('GBP')
        ->and($nextScope->for(CurrencyRole::Display)->code)->toBe('JPY')
        ->and($context->for(CurrencyRole::Display)->code)->toBe($expected);
})->with(['base display' => [null, 'USD'], 'separate display' => ['EUR', 'EUR']]);

it('formats money using the requested locale', function () {
    $money = new Money(123456, new Currency('USD', 2, '$'));

    expect((new MoneyFormatter())->format($money, 'en_US'))->toBe('$1,234.56');
});

it('uses an injected formatter and falls back when formatting fails', function (string|false $formatted, string $expected) {
    $formatter = Mockery::mock();
    $formatter->shouldReceive('formatCurrency')->once()->with(1234.56, 'USD')->andReturn($formatted);
    $factory = function (string $locale) use ($formatter): object {
        expect($locale)->toBe('en_US');

        return $formatter;
    };

    expect((new MoneyFormatter($factory))->format(new Money(123456, new Currency('USD', 2, '$')), 'en_US'))
        ->toBe($expected);
})->with(['custom formatting' => ['custom amount', 'custom amount'], 'formatting failure' => [false, 'USD 1234.56']]);
