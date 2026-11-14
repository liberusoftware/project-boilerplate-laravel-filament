<?php

use Liberu\Foundation\Localization\Translation\TranslationRegistry;
use Liberu\Localization\Contracts\TranslationProvider;
use Liberu\Localization\Contracts\TranslationProviderRegistry;

it('registers translation providers behind the neutral contract', function () {
    $provider = new class() implements TranslationProvider
    {
        public function name(): string
        {
            return 'test';
        }

        public function translate(string $text, string $targetLanguage, string $sourceLanguage = 'en'): string
        {
            return "{$targetLanguage}:{$text}";
        }

        public function translateBatch(array $texts, string $targetLanguage, string $sourceLanguage = 'en'): array
        {
            return $texts;
        }

        public function supportedLanguages(): array
        {
            return ['en' => 'English'];
        }
    };
    $registry = new TranslationRegistry();
    $registry->register($provider);

    expect($registry->get('test'))->toBe($provider)
        ->and($registry->all())->toBe(['test' => $provider]);
});

it('rejects duplicate provider names', function () {
    $provider = new class() implements TranslationProvider
    {
        public function name(): string
        {
            return 'test';
        }

        public function translate(string $text, string $targetLanguage, string $sourceLanguage = 'en'): string
        {
            return $text;
        }

        public function translateBatch(array $texts, string $targetLanguage, string $sourceLanguage = 'en'): array
        {
            return $texts;
        }

        public function supportedLanguages(): array
        {
            return [];
        }
    };
    $registry = new TranslationRegistry();
    $registry->register($provider);
    $registry->register($provider);
})->throws(InvalidArgumentException::class);

it('rejects unknown provider names with an explicit error', function () {
    expect(fn () => (new TranslationRegistry())->get('missing'))->toThrow(InvalidArgumentException::class, 'Unknown translation provider [missing].');
});

it('resolves the neutral registry contract to the shared package instance', function () {
    expect(app(TranslationProviderRegistry::class))->toBe(app(TranslationRegistry::class));
});
