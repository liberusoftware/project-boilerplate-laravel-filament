<?php

use Liberu\Foundation\Localization\Formatting\LocaleFormatter;

it('formats dates in the requested locale and timezone', function () {
    $formatter = new LocaleFormatter();
    $date = new DateTimeImmutable('2026-01-01T00:30:00Z');
    expect($formatter->date($date, 'en_US', 'UTC'))->toBe('Jan 1, 2026')
        ->and($formatter->date($date, 'en_US', 'America/Los_Angeles'))->toBe('Dec 31, 2025');
});

it('formats decimal separators and preserves zero', function () {
    $formatter = new LocaleFormatter();
    expect($formatter->number(1234.5, 'en_US'))->toBe('1,234.5')
        ->and($formatter->number(1234.5, 'de_DE'))->toBe('1.234,5')
        ->and($formatter->number(0, 'en_US'))->toBe('0');
});

it('joins empty single paired and longer display lists', function () {
    $formatter = new LocaleFormatter();
    expect($formatter->list([], 'en'))->toBe('')->and($formatter->list(['One'], 'en'))->toBe('One')
        ->and($formatter->list(['One', 'Two'], 'en'))->toBe('One and Two')
        ->and($formatter->list(['One', 'Two'], 'de'))->toBe('One Two')
        ->and($formatter->list(['One', 'Two', 'Three'], 'en'))->toBe('One, Two, Three');
});
