<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Http\Request;
use Zephyrus\Localization\LocaleLoaderInterface;

/**
 * Unit and light-integration tests for Application::transFromRequest(), using an in-memory loader.
 */
final class ApplicationLocalizationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeLoader(): LocaleLoaderInterface
    {
        return new class implements LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return match ($locale) {
                    'fr'    => ['greeting' => 'Bonjour', 'bye' => 'Au revoir'],
                    'de'    => ['greeting' => 'Guten Tag'],
                    'es'    => ['greeting' => 'Hola'],
                    default => ['greeting' => 'Hello', 'bye' => 'Goodbye'],
                };
            }
        };
    }

    private function requestWithHeader(string $acceptLanguage): Request
    {
        return Request::fromArray('GET', '/', headers: ['accept-language' => $acceptLanguage]);
    }

    protected function tearDown(): void
    {
        App::reset();
    }

    // -------------------------------------------------------------------------
    // trans(): existing behaviour unchanged
    // -------------------------------------------------------------------------

    public function testTransWithExplicitLocale(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        self::assertSame('Bonjour', $app->trans('greeting', locale: 'fr'));
    }

    public function testTransFallsToDefaultLocaleWhenMissing(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        self::assertSame('Goodbye', $app->trans('bye', locale: 'de'));
    }

    // -------------------------------------------------------------------------
    // resolveLocaleFromRequest() helper
    // -------------------------------------------------------------------------

    public function testResolveLocaleFromRequestReturnsNullWhenNoSignalsProvided(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        self::assertNull($app->resolveLocaleFromRequest());
    }

    public function testResolveLocaleFromRequestUsesRequestedLocaleThenHeaderThenDefault(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->withSupportedLocales(['en', 'fr'])
            ->build();

        $request = $this->requestWithHeader('de, fr;q=0.9');

        self::assertSame('fr', $app->resolveLocaleFromRequest(request: $request));
        self::assertSame('fr', $app->resolveLocaleFromRequest(request: $request, requestedLocale: 'fr-CA'));
    }

    // -------------------------------------------------------------------------
    // transFromRequest(): no signals, so the translator default locale
    // -------------------------------------------------------------------------

    public function testTransFromRequestNoRequestNoLocaleUsesTranslatorDefault(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'fr')
            ->build();

        self::assertSame('Bonjour', $app->transFromRequest('greeting'));
    }

    public function testTransFromRequestNoRequestNoLocaleWithEnDefault(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        self::assertSame('Hello', $app->transFromRequest('greeting'));
    }

    // -------------------------------------------------------------------------
    // transFromRequest(): Accept-Language header
    // -------------------------------------------------------------------------

    public function testTransFromRequestUsesAcceptLanguageHeader(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        $response = $app->transFromRequest('greeting', request: $this->requestWithHeader('fr, en;q=0.9'));

        self::assertSame('Bonjour', $response);
    }

    public function testTransFromRequestQValueOrderingRespected(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        $response = $app->transFromRequest('greeting', request: $this->requestWithHeader('de;q=0.5, fr;q=0.9, en;q=0.8'));

        self::assertSame('Bonjour', $response);
    }

    public function testTransFromRequestEmptyHeaderUsesDefault(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        $response = $app->transFromRequest('greeting', request: $this->requestWithHeader(''));

        self::assertSame('Hello', $response);
    }

    // -------------------------------------------------------------------------
    // transFromRequest(): explicit requestedLocale
    // -------------------------------------------------------------------------

    public function testTransFromRequestExplicitRequestedLocaleWins(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        $response = $app->transFromRequest(
            'greeting',
            request:         $this->requestWithHeader('de'),
            requestedLocale: 'fr',
        );

        self::assertSame('Bonjour', $response);
    }

    public function testTransFromRequestRequestedLocaleWithNullRequest(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        self::assertSame('Bonjour', $app->transFromRequest('greeting', requestedLocale: 'fr'));
    }

    public function testTransFromRequestRequestedLocaleNormalized(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        self::assertSame('Bonjour', $app->transFromRequest('greeting', requestedLocale: 'FR'));
    }

    // -------------------------------------------------------------------------
    // transFromRequest(): per-call supportedLocales override
    // -------------------------------------------------------------------------

    public function testTransFromRequestPerCallSupportedLocalesFilter(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        $response = $app->transFromRequest(
            'greeting',
            request:         $this->requestWithHeader('de;q=0.9, fr;q=0.8, en;q=0.7'),
            supportedLocales: ['en', 'fr'],
        );

        self::assertSame('Bonjour', $response);
    }

    public function testTransFromRequestPerCallSupportedLocalesOverrideAppLevel(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->withSupportedLocales(['en', 'fr'])          // app-level: only en/fr
            ->build();

        $response = $app->transFromRequest(
            'greeting',
            request:         $this->requestWithHeader('de, fr'),
            supportedLocales: ['de', 'fr'],
        );

        self::assertSame('Guten Tag', $response);
    }

    /**
     * @return array<string, array{0: array<array-key, mixed>, 1: string}>
     */
    public static function refusedPerCallLists(): array
    {
        return [
            'a null entry' => [['fr', null], 'resolveLocaleFromRequest(): $supportedLocales[1] null is refused: each entry must be a string.'],
            'a comma entry' => [['fr,de'], 'resolveLocaleFromRequest(): $supportedLocales[0] "fr,de" is refused: must be one locale; use list items, not a comma inside one item.'],
            'a wildcard' => [['*'], 'resolveLocaleFromRequest(): $supportedLocales[0] "*" is refused: must be a locale tag, such as fr or pt-BR.'],
            'only blank entries' => [['', ' '], 'resolveLocaleFromRequest(): $supportedLocales names no locale, which would accept every locale; pass at least one, or an empty list to accept every locale.'],
            'a 1000-byte key' => [[str_repeat('a', 1000) => 'fr,de'], 'resolveLocaleFromRequest(): $supportedLocales["' . str_repeat('a', 64) . '..." (1000 bytes)] "fr,de" is refused: must be one locale; use list items, not a comma inside one item.'],
        ];
    }

    /**
     * @param array<array-key, mixed> $supportedLocales
     */
    #[DataProvider('refusedPerCallLists')]
    public function testAPerCallSupportedLocalesListIsRefusedLikeTheBuilderRefusesIt(array $supportedLocales, string $message): void
    {
        $app = ApplicationBuilder::create()->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')->build();

        try {
            $app->resolveLocaleFromRequest(requestedLocale: 'fr', supportedLocales: $supportedLocales);
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    public function testTransFromRequestRefusesAPerCallListWithoutResolvingFirst(): void
    {
        $app = ApplicationBuilder::create()->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')->build();

        try {
            $app->transFromRequest('greeting', supportedLocales: ['fr,de']);
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'transFromRequest(): $supportedLocales[0] "fr,de" is refused: must be one locale; use list items, not a comma inside one item.',
                $exception->getMessage(),
            );
        }
    }

    public function testAnEmptyPerCallListStillAcceptsEveryLocale(): void
    {
        $app = ApplicationBuilder::create()->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')->build();

        self::assertSame('de', $app->resolveLocaleFromRequest(requestedLocale: 'de', supportedLocales: []));
        self::assertSame('fr', $app->resolveLocaleFromRequest(requestedLocale: 'fr', supportedLocales: ['fr', 'en']));
    }

    // -------------------------------------------------------------------------
    // transFromRequest(): app-level supportedLocales
    // -------------------------------------------------------------------------

    public function testTransFromRequestAppLevelSupportedLocalesUsedByDefault(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->withSupportedLocales(['en', 'fr'])
            ->build();

        $response = $app->transFromRequest(
            'greeting',
            request: $this->requestWithHeader('de, fr'),
        );

        self::assertSame('Bonjour', $response);
    }

    public function testTransFromRequestAppLevelFallsToDefaultWhenNoneSupported(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->withSupportedLocales(['en', 'fr'])
            ->build();

        $response = $app->transFromRequest(
            'greeting',
            request: $this->requestWithHeader('de, es'),
        );

        self::assertSame('Hello', $response);
    }

    // -------------------------------------------------------------------------
    // transFromRequest(): regional fallback
    // -------------------------------------------------------------------------

    public function testTransFromRequestRegionalFallbackViaSupportedList(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->withSupportedLocales(['en', 'fr'])
            ->build();

        $response = $app->transFromRequest(
            'greeting',
            request: $this->requestWithHeader('fr-CA'),
        );

        self::assertSame('Bonjour', $response);
    }

    public function testTransFromRequestRequestedLocaleRegionalFallback(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->withSupportedLocales(['en', 'fr'])
            ->build();

        $response = $app->transFromRequest(
            'greeting',
            requestedLocale: 'fr-CA',
        );

        self::assertSame('Bonjour', $response);
    }

    // -------------------------------------------------------------------------
    // transFromRequest(): parameter interpolation
    // -------------------------------------------------------------------------

    public function testTransFromRequestInterpolatesParameters(): void
    {
        $loader = new class implements LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return $locale === 'fr'
                    ? ['welcome' => 'Bienvenue {name}']
                    : ['welcome' => 'Welcome {name}'];
            }
        };

        $app = ApplicationBuilder::create()
            ->withLocaleLoader($loader, defaultLocale: 'en')
            ->build();

        $response = $app->transFromRequest(
            'welcome',
            ['name' => 'Alice'],
            request: $this->requestWithHeader('fr'),
        );

        self::assertSame('Bienvenue Alice', $response);
    }
}
