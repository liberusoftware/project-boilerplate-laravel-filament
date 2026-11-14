<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Liberu\Foundation\Localization\MyMemory\TranslationService;

test('translation service translates text correctly', function () {
    $service = new TranslationService();

    Http::fake([
        'api.mymemory.translated.net/*' => Http::response([
            'responseData' => [
                'translatedText' => 'Hola',
            ],
        ], 200),
    ]);

    $result = $service->translate('Hello', 'es', 'en');

    expect($result)->toBe('Hola');
});

test('translation service returns original text if source and target are same', function () {
    $service = new TranslationService();

    $result = $service->translate('Hello', 'en', 'en');

    expect($result)->toBe('Hello');
});

test('translation service uses cache', function () {
    Cache::flush();

    $service = new TranslationService();

    Http::fake([
        'api.mymemory.translated.net/*' => Http::response([
            'responseData' => [
                'translatedText' => 'Hola',
            ],
        ], 200),
    ]);

    // First call should hit the API
    $result1 = $service->translate('Hello', 'es', 'en');

    // Second call should use cache
    $result2 = $service->translate('Hello', 'es', 'en');

    expect($result1)->toBe('Hola');
    expect($result2)->toBe('Hola');

    // The second call must be served from cache — only ONE API request total.
    Http::assertSentCount(1);

    $cacheKey = 'translation:en:es:'.md5('Hello');
    expect(Cache::has($cacheKey))->toBeTrue();
});

test('translation service returns original text when the API connection throws', function () {
    Cache::flush();

    $service = new TranslationService();

    Http::fake(function () {
        throw new ConnectionException('offline');
    });

    expect($service->translate('Hello', 'es', 'en'))->toBe('Hello');
});

test('translation service handles API failures gracefully', function () {
    Cache::flush();

    $service = new TranslationService();

    Http::fake([
        'api.mymemory.translated.net/*' => Http::response([], 500),
    ]);

    $result = $service->translate('Hello', 'es', 'en');

    // Should return original text on failure
    expect($result)->toBe('Hello');
});

test('translation service can translate batch', function () {
    $service = new TranslationService();

    Http::fake([
        'api.mymemory.translated.net/*' => Http::response([
            'responseData' => [
                'translatedText' => 'Translated',
            ],
        ], 200),
    ]);

    $texts = [
        'hello' => 'Hello',
        'world' => 'World',
    ];

    $results = $service->translateBatch($texts, 'es', 'en');

    expect($results)->toBe([
        'hello' => 'Translated',
        'world' => 'Translated',
    ]);
    Http::assertSentCount(2);
});

test('translation service can check if language is supported', function () {
    $service = new TranslationService();

    expect($service->isLanguageSupported('en'))->toBeTrue();
    expect($service->isLanguageSupported('es'))->toBeTrue();
    expect($service->isLanguageSupported('fr'))->toBeTrue();
    expect($service->isLanguageSupported('de'))->toBeTrue();
    expect($service->isLanguageSupported('xx'))->toBeFalse();
});

test('translation service returns supported languages', function () {
    $service = new TranslationService();

    $languages = $service->getSupportedLanguages();

    expect($languages)->toBeArray();
    expect($languages)->toHaveKey('en');
    expect($languages)->toHaveKey('es');
    expect($languages)->toHaveKey('fr');
    expect($languages)->toHaveKey('de');
});

it('preserves nested batch keys and non-string values while translating text', function () {
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake(['api.mymemory.translated.net/*' => Http::response([
        'responseData' => ['translatedText' => 'Traduit'],
    ])]);

    $input = ['heading' => 'Hello', 'nested' => [5 => 'World', 'count' => 0, 'enabled' => false, 'unset' => null], 'empty' => []];

    expect((new TranslationService())->translateBatch($input, 'fr'))->toBe([
        'heading' => 'Traduit',
        'nested' => [5 => 'Traduit', 'count' => 0, 'enabled' => false, 'unset' => null],
        'empty' => [],
    ]);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request['q'] === 'Hello' && $request['langpair'] === 'en|fr');
});

it('returns the original text for a malformed cache entry without requesting the API', function () {
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake();
    Cache::put('translation:en:es:'.md5('Hello'), ['unexpected' => true], 60);

    expect((new TranslationService())->translate('Hello', 'es'))->toBe('Hello');
    Http::assertNothingSent();
});

it('does not cache successful responses without a usable translation', function ($payload) {
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake(['api.mymemory.translated.net/*' => Http::response($payload)]);

    expect((new TranslationService())->translate('Hello', 'es'))->toBe('Hello')
        ->and(Cache::has('translation:en:es:'.md5('Hello')))->toBeFalse();
})->with(['missing' => [[]], 'empty' => [['responseData' => ['translatedText' => '']]], 'invalid type' => [['responseData' => ['translatedText' => 42]]]]);
