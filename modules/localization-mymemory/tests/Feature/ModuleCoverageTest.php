<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Liberu\Foundation\Localization\MyMemory\MyMemoryServiceProvider;
use Liberu\Foundation\Localization\MyMemory\TranslationService;
use Liberu\Foundation\Localization\Translation\TranslationRegistry;
use Liberu\Localization\Contracts\TranslationProviderRegistry;

it('registers TranslationService via MyMemoryServiceProvider into TranslationProviderRegistry', function (): void {
    $registry = new TranslationRegistry();
    $this->app->instance(TranslationProviderRegistry::class, $registry);

    $provider = new MyMemoryServiceProvider($this->app);
    $provider->boot();

    expect($registry->get('mymemory'))->toBeInstanceOf(TranslationService::class);
});

it('covers TranslationService name, language support, caching, http success/empty/failure, and recursive batching', function (): void {
    Cache::flush();

    $service = new TranslationService();

    expect($service->name())->toBe('mymemory')
        ->and($service->getSupportedLanguages())->toBe(TranslationService::SUPPORTED_LANGUAGES)
        ->and($service->supportedLanguages())->toBe(TranslationService::SUPPORTED_LANGUAGES)
        ->and($service->isLanguageSupported('en'))->toBeTrue()
        ->and($service->isLanguageSupported('fr'))->toBeTrue()
        ->and($service->isLanguageSupported('zz'))->toBeFalse();

    // Same source and target short-circuit
    expect($service->translate('Hello', 'en', 'en'))->toBe('Hello');

    // Successful HTTP translation and cache hit
    Http::fake([
        'https://api.mymemory.translated.net/get*' => Http::sequence()
            ->push(['responseData' => ['translatedText' => 'Bonjour']], 200)
            ->push(['responseData' => ['translatedText' => '']], 200)
            ->push([], 500)
            ->push(['responseData' => ['translatedText' => 'Salut']], 200),
    ]);

    expect($service->translate('Hello', 'fr', 'en'))->toBe('Bonjour');
    // Cached hit (string)
    expect($service->translate('Hello', 'fr', 'en'))->toBe('Bonjour');

    // Cached non-string fallback
    $nonStringKey = 'translation:en:de:'.md5('NonString');
    Cache::put($nonStringKey, ['not-a-string'], 60);
    expect($service->translate('NonString', 'de', 'en'))->toBe('NonString');

    // Empty translatedText falls back to original
    expect($service->translate('EmptyResp', 'fr', 'en'))->toBe('EmptyResp');

    // Non-200 HTTP response falls back to original
    expect($service->translate('FailedResp', 'fr', 'en'))->toBe('FailedResp');

    // Recursive batch translation with nested arrays, strings, and non-string values
    $batch = $service->translateBatch([
        'greeting' => 'Hi',
        'nested' => ['same' => 'Keep'],
        'count' => 42,
    ], 'fr', 'en');

    expect($batch['greeting'])->toBe('Salut')
        ->and($batch['count'])->toBe(42)
        ->and($batch['nested'])->toBeArray();

    // Exception path logs error and returns original text
    Log::spy();
    Http::fake(static function (): never {
        throw new RuntimeException('Network unreachable');
    });

    expect($service->translate('Boom', 'es', 'en'))->toBe('Boom');
    Log::shouldHaveReceived('error')->once();
});
