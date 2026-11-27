<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;
use Liberu\Foundation\Localization\Context\LocaleContext;
use Liberu\Foundation\Localization\Context\LocaleResolver;
use Liberu\Foundation\Localization\Formatting\LocaleFormatter;
use Liberu\Foundation\Localization\Http\Middleware\SetLocale;
use Liberu\Foundation\Localization\LocalizationServiceProvider;
use Liberu\Foundation\Localization\Translation\TranslationRegistry;
use Liberu\Localization\Contracts\TranslationProvider;
use Liberu\Localization\Contracts\TranslationProviderRegistry;
use Symfony\Component\HttpFoundation\Response;

it('boots localization service provider and exercises LocaleContext payload and LocaleResolver fallbacks', function (): void {
    $provider = $this->app->register(LocalizationServiceProvider::class, force: true);
    $provider->register();

    expect(app(LocaleResolver::class))->toBeInstanceOf(LocaleResolver::class)
        ->and(app(TranslationProviderRegistry::class))->toBeInstanceOf(TranslationRegistry::class);

    $context = new LocaleContext('ar', 'UTC', 'rtl');
    expect($context->payload())->toBe([
        'locale' => 'ar',
        'timezone' => 'UTC',
    ])->and($context->direction)->toBe('rtl');

    config()->set('localization.locales', ['en' => 'English', 'fr' => 'French', 'ar' => 'Arabic']);
    config()->set('localization.rtl_locales', ['ar']);
    config()->set('localization.team_timezone', 'Invalid/Timezone_Name');
    config()->set('localization.site_timezone', 'Europe/London');

    $resolver = new LocaleResolver();

    // Invalid timezone falls back to UTC, RTL locale sets rtl direction
    $request = Request::create('/test', 'GET', ['locale' => 'ar']);
    $resolved = $resolver->resolve($request);

    expect($resolved->locale)->toBe('ar')
        ->and($resolved->timezone)->toBe('UTC')
        ->and($resolved->direction)->toBe('rtl');

    // Valid timezone and LTR locale
    config()->set('localization.team_timezone', 'Europe/Paris');
    $requestFr = Request::create('/test', 'GET', ['locale' => 'fr']);
    $resolvedFr = $resolver->resolve($requestFr);

    expect($resolvedFr->locale)->toBe('fr')
        ->and($resolvedFr->timezone)->toBe('Europe/Paris')
        ->and($resolvedFr->direction)->toBe('ltr');
});

it('sets locale in session when SetLocale middleware receives a supported locale query parameter', function (): void {
    config()->set('localization.locales', ['en' => 'English', 'de' => 'German']);
    config()->set('localization.team_timezone', 'UTC');

    $middleware = new SetLocale();
    $request = Request::create('/dashboard', 'GET', ['locale' => 'de']);

    $response = $middleware->handle($request, static fn (Request $req): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok')
        ->and(App::getLocale())->toBe('de')
        ->and(Session::get('locale'))->toBe('de')
        ->and($request->attributes->get(LocaleContext::class))->toBeInstanceOf(LocaleContext::class);
});

it('registers, retrieves, lists, and guards against duplicate or unknown providers in TranslationRegistry', function (): void {
    $registry = new TranslationRegistry();

    $provider = new class() implements TranslationProvider
    {
        public function name(): string
        {
            return 'stub';
        }

        public function translate(string $text, string $targetLang, string $sourceLang = 'en'): string
        {
            return $text;
        }

        public function supportedLanguages(): array
        {
            return ['en' => 'English'];
        }
    };

    $registry->register($provider);

    expect($registry->get('stub'))->toBe($provider)
        ->and($registry->all())->toBe(['stub' => $provider]);

    expect(fn () => $registry->register($provider))
        ->toThrow(InvalidArgumentException::class, 'Duplicate translation provider [stub].');

    expect(fn () => $registry->get('missing'))
        ->toThrow(InvalidArgumentException::class, 'Unknown translation provider [missing].');
});

it('formats dates, numbers, and lists with LocaleFormatter', function (): void {
    $formatter = new LocaleFormatter();

    expect($formatter->date(new DateTimeImmutable('2026-05-10 12:00:00', new DateTimeZone('UTC')), 'en_US'))
        ->not->toBeEmpty()
        ->and($formatter->number(1234.5, 'en_US'))->not->toBeEmpty()
        ->and($formatter->list(['A', 'B'], 'en'))->toBe('A and B')
        ->and($formatter->list(['A', 'B'], 'fr'))->toBe('A B')
        ->and($formatter->list(['A', 'B', 'C'], 'en'))->toBe('A, B, C');
});
