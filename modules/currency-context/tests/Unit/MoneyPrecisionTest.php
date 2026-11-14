<?php

use Liberu\Foundation\Currency\Exceptions\CurrencyMismatch;
use Liberu\Foundation\Currency\ValueObjects\Currency;
use Liberu\Foundation\Currency\ValueObjects\Money;

it('renders minor units exactly without floating point conversion', function (int $amount, int $minorUnits, string $expected) {
    expect((new Money($amount, new Currency('XTS', $minorUnits, '¤')))->decimal())->toBe($expected);
})->with([
    'zero amount' => [0, 2, '0.00'],
    'fractional amount' => [1, 2, '0.01'],
    'negative fraction' => [-1, 2, '-0.01'],
    'three minor digits' => [1, 3, '0.001'],
    'no minor digits' => [-123, 0, '-123'],
    'maximum precision' => [1234567, 6, '1.234567'],
    'largest signed amount' => [PHP_INT_MAX, 2, '92233720368547758.07'],
    'smallest signed amount' => [PHP_INT_MIN, 2, '-92233720368547758.08'],
]);

it('subtracts without modifying either operand', function () {
    $usd = new Currency('USD', 2, '$');
    $original = new Money(100, $usd);
    $other = new Money(40, $usd);
    $difference = $original->subtract($other);

    expect($difference->minorAmount)->toBe(60)
        ->and($difference->currency)->toBe($usd)
        ->and($original->minorAmount)->toBe(100)
        ->and($other->minorAmount)->toBe(40);
});

it('compares both amount and currency when checking equality', function () {
    $usd = new Currency('USD', 2, '$');
    $money = new Money(100, $usd);

    expect($money->equals(new Money(100, new Currency('USD', 2, '$'))))->toBeTrue()
        ->and($money->equals(new Money(99, $usd)))->toBeFalse()
        ->and($money->equals(new Money(100, new Currency('EUR', 2, '€'))))->toBeFalse();
});

it('rejects subtraction between different currencies', function () {
    expect(fn () => (new Money(100, new Currency('USD', 2, '$')))->subtract(new Money(40, new Currency('EUR', 2, '€'))))
        ->toThrow(CurrencyMismatch::class, 'Cannot combine USD and EUR.');
});
