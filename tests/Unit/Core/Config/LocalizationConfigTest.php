<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\LocalizationConfig;
use Zephyrus\Exceptions\MessageValue;
use Zephyrus\Formatting\FormatterInput;
use Zephyrus\Tests\Unit\Core\Cache\CacheDirectory;
use Zephyrus\Tests\Unit\Formatting\FormatterInputTest;

final class LocalizationConfigTest extends TestCase
{
    public function testFromArrayDefaultsAreApplied(): void
    {
        $config = LocalizationConfig::fromArray([]);

        self::assertSame('en', $config->locale);
        self::assertSame([], $config->supportedLocales);
        self::assertNull($config->localePath);
        self::assertSame('UTC', $config->timezone);
        self::assertNull($config->currency);
    }

    public function testFromArrayNormalizesValuesAndSupportsSnakeCaseKeys(): void
    {
        $config = LocalizationConfig::fromArray([
            'locale' => ' FR-CA ',
            'supported_locales' => [' FR ', '', 'EN'],
            'locale_path' => ' /app/locales ',
            'timezone' => 'America/Montreal',
            'currency' => ' CAD ',
        ]);

        self::assertSame('fr-ca', $config->locale);
        self::assertSame(['fr', 'en'], $config->supportedLocales);
        self::assertSame('/app/locales', $config->localePath);
        self::assertSame('America/Montreal', $config->timezone);
        self::assertSame('CAD', $config->currency);
    }

    public function testFromArrayRejectsEmptyLocale(): void
    {
        $this->expectException(ConfigurationException::class);

        LocalizationConfig::fromArray(['locale' => '   ']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notDefaultLocaleTags(): array
    {
        return [
            'a path' => ['../x'],
            'a wildcard' => ['*'],
            'a header injection' => ["fr\r\nX"],
            'a lone hyphen' => ['-'],
            'an inner space' => ['fr en'],
            'a trailing form feed' => ["fr\f"],
            'a well-formed tag one byte over the limit' => ['ab-abcdefgh-abcdefgh-abcdefgh-abcdef'],
        ];
    }

    #[DataProvider('notDefaultLocaleTags')]
    public function testADefaultLocaleThatIsNotALocaleTagIsRefused(string $locale): void
    {
        try {
            LocalizationConfig::fromArray(['locale' => $locale]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame(
                "Configuration section 'localization' field 'locale' has invalid value "
                . MessageValue::quote($locale) . ': must be a locale tag, such as fr or pt-BR.',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function defaultLocaleTags(): array
    {
        return [
            'a language' => ['fr', 'fr'],
            'an underscore region' => ['fr_CA', 'fr_ca'],
            'a hyphen region' => ['pt-BR', 'pt-br'],
            'a script and region' => ['zh-Hant-TW', 'zh-hant-tw'],
            'a tag at the length limit' => ['ab-abcdefgh-abcdefgh-abcdefgh-abcde', 'ab-abcdefgh-abcdefgh-abcdefgh-abcde'],
            'surrounding spaces' => [' fr ', 'fr'],
        ];
    }

    #[DataProvider('defaultLocaleTags')]
    public function testADefaultLocaleThatIsALocaleTagIsAcceptedLowerCased(string $locale, string $expected): void
    {
        self::assertSame($expected, LocalizationConfig::fromArray(['locale' => $locale])->locale);
    }

    public function testFromArrayRejectsEmptyTimezone(): void
    {
        $this->expectException(ConfigurationException::class);

        LocalizationConfig::fromArray(['timezone' => '']);
    }

    public function testFromArrayLegacyDefaultLocaleKeyIsAccepted(): void
    {
        $config = LocalizationConfig::fromArray([
            'defaultLocale' => 'fr',
        ]);

        self::assertSame('fr', $config->locale);
    }

    public function testFromArrayLegacySnakeCaseDefaultLocaleKeyIsAccepted(): void
    {
        $config = LocalizationConfig::fromArray([
            'default_locale' => 'de',
        ]);

        self::assertSame('de', $config->locale);
    }

    public function testFromArrayLegacyJsonLocalePathsConvertsToSinglePath(): void
    {
        $config = LocalizationConfig::fromArray([
            'jsonLocalePaths' => ['/base/locales', '/override/locales'],
        ]);

        // Takes last non-empty path
        self::assertSame('/override/locales', $config->localePath);
    }

    public function testFromArrayLegacySnakeCaseJsonLocalePathsConvertsToSinglePath(): void
    {
        $config = LocalizationConfig::fromArray([
            'json_locale_paths' => ['/app/locales'],
        ]);

        self::assertSame('/app/locales', $config->localePath);
    }

    public function testFromArrayLegacyEmptyJsonLocalePathsResultsInNull(): void
    {
        $config = LocalizationConfig::fromArray([
            'jsonLocalePaths' => [],
        ]);

        self::assertNull($config->localePath);
    }

    public function testASingleSupportedLocaleStringIsReadAsOneEntry(): void
    {
        self::assertSame(['fr'], LocalizationConfig::fromArray(['supportedLocales' => ' FR '])->supportedLocales);
    }

    public function testASupportedLocaleEntryThatIsAnArrayIsRefusedNamingItsIndex(): void
    {
        try {
            LocalizationConfig::fromArray(['supported_locales' => [['en'], 'en']]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'localization' field 'supportedLocales[0]' has invalid value array: each entry must be a string.",
                $exception->getMessage(),
            );
        }
    }

    public function testASupportedLocaleEntryThatIsAnIntegerIsRefusedNamingItsIndex(): void
    {
        try {
            LocalizationConfig::fromArray(['supportedLocales' => ['fr', 1]]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'localization' field 'supportedLocales[1]' has invalid value 1: each entry must be a string.",
                $exception->getMessage(),
            );
        }
    }

    public function testNullAndBlankSupportedLocaleEntriesAreDroppedSilently(): void
    {
        self::assertSame(['fr'], LocalizationConfig::fromArray(['supportedLocales' => [null, ' FR ', '']])->supportedLocales);
    }

    public function testASupportedLocalesValueThatIsAnObjectIsRefused(): void
    {
        try {
            LocalizationConfig::fromArray(['supportedLocales' => new \stdClass()]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'localization' field 'supportedLocales' has invalid value stdClass: must be a string or a list of strings.",
                $exception->getMessage(),
            );
        }
    }

    public function testASupportedLocalesValueThatIsAnIntegerIsRefused(): void
    {
        try {
            LocalizationConfig::fromArray(['supportedLocales' => 1]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'localization' field 'supportedLocales' has invalid value 1: must be a string or a list of strings.",
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function emptiedSupportedLocales(): array
    {
        return [
            'a null entry' => [[null]],
            'an empty string entry' => [['']],
            'blank entries' => [[null, '  ', "\t"]],
            'a blank string' => ['  '],
        ];
    }

    #[DataProvider('emptiedSupportedLocales')]
    public function testASupportedLocalesDeclarationThatNamesNoLocaleIsRefused(mixed $declared): void
    {
        try {
            LocalizationConfig::fromArray(['supportedLocales' => $declared]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'localization' field 'supportedLocales' has invalid value "
                . MessageValue::describe($declared)
                . ': names no locale, which would accept every locale; name at least one, or remove the key to accept every locale; an unset !env without a default reads as null; add a default, such as !env NAME, fr.',
                $exception->getMessage(),
            );
        }
    }

    public function testASupportedLocaleEntryWithACommaIsRefusedNamingItsIndex(): void
    {
        try {
            LocalizationConfig::fromArray(['supportedLocales' => ['de', 'fr,en']]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'localization' field 'supportedLocales[1]' has invalid value \"fr,en\": must be one locale; use list items, not a comma inside one item.",
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return array<string, array{0: list<string>, 1: int}>
     */
    public static function notLocaleTags(): array
    {
        return [
            'a hyphen' => [['-'], 0],
            'an underscore' => [['fr', '_'], 1],
            'a spaced hyphen' => [[' - '], 0],
            'thirty-six bytes' => [[str_repeat('a', 36)], 0],
            'a well-formed tag of thirty-six bytes' => [['ab-abcdefgh-abcdefgh-abcdefgh-abcdef'], 0],
            'semicolon separated tags' => [['fr-CA;en-CA;fr-FR;en-US;es-ES;de-DE;it-IT'], 0],
            'an internal space' => [['fr en de es it pt nl sv da no fi pl ru'], 0],
            'a wildcard' => [['*'], 0],
            'a no-break space inside' => [["fr\u{a0}en"], 0],
            'a form feed' => [["\f"], 0],
        ];
    }

    /**
     * @param list<string> $declared
     */
    #[DataProvider('notLocaleTags')]
    public function testASupportedLocaleEntryThatIsNotALocaleTagIsRefusedNamingItsIndex(array $declared, int $index): void
    {
        try {
            LocalizationConfig::fromArray(['supportedLocales' => $declared]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'localization' field 'supportedLocales[$index]' has invalid value "
                . MessageValue::describe($declared[$index])
                . ': must be a locale tag, such as fr or pt-BR.',
                $exception->getMessage(),
            );
        }
    }

    public function testAWellFormedSupportedLocaleOfThirtyFiveBytesIsKept(): void
    {
        $tag = substr('ab-abcdefgh-abcdefgh-abcdefgh-abcdef', 0, 35);

        self::assertSame([$tag], LocalizationConfig::fromArray(['supportedLocales' => [$tag]])->supportedLocales);
    }

    public function testAScalarSupportedLocalesThatIsNotALocaleTagIsRefusedWithoutAnIndex(): void
    {
        try {
            LocalizationConfig::fromArray(['supported_locales' => 'fr;en']);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'localization' field 'supportedLocales' has invalid value \"fr;en\": must be a locale tag, such as fr or pt-BR.",
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function supportedLocalesKeys(): array
    {
        return [
            'the snake case key' => ['supported_locales'],
            'the camel case key' => ['supportedLocales'],
        ];
    }

    #[DataProvider('supportedLocalesKeys')]
    public function testADeclaredNullSupportedLocalesIsRefused(string $key): void
    {
        try {
            LocalizationConfig::fromArray([$key => null]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'localization' field 'supportedLocales' has invalid value null: names no locale, which would accept every locale; name at least one, or remove the key to accept every locale"
                . "; an unset !env without a default reads as null; add a default, such as !env NAME, fr.",
                $exception->getMessage(),
            );
        }
    }

    public function testAYamlKeyLeftEmptyOrUnsetEnvIsRefusedLikeANull(): void
    {
        $directory = new CacheDirectory();
        $file = $directory->file('config.yml', "localization:\n  supported_locales: !env ZEPHYRUS_TEST_UNSET_LOCALES\n");

        try {
            Configuration::fromYamlFile($file);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'localization' field 'supportedLocales' has invalid value null: names no locale, which would accept every locale; name at least one, or remove the key to accept every locale"
                . "; an unset !env without a default reads as null; add a default, such as !env NAME, fr.",
                $exception->getMessage(),
            );
        } finally {
            $directory->remove();
        }
    }

    private const string NULL_LOCALE_REFUSAL = "Configuration section 'localization' field 'locale' has invalid value null: "
        . 'must be a locale tag, such as fr or pt-BR; an unset !env without a default reads as null; '
        . 'add a default, such as !env NAME, fr.';

    public function testADeclaredNullDefaultLocaleIsRefused(): void
    {
        try {
            LocalizationConfig::fromArray(['locale' => null]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(self::NULL_LOCALE_REFUSAL, $exception->getMessage());
        }
    }

    #[DataProvider('unsetYamlLocales')]
    public function testAYamlDefaultLocaleLeftEmptyOrUnsetEnvIsRefusedLikeANull(string $declaration): void
    {
        $directory = new CacheDirectory();
        $file = $directory->file('config.yml', "localization:\n  locale: " . $declaration . "\n");

        try {
            Configuration::fromYamlFile($file);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(self::NULL_LOCALE_REFUSAL, $exception->getMessage());
        } finally {
            $directory->remove();
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsetYamlLocales(): array
    {
        return [
            'a tilde' => ['~'],
            'a value left empty' => [''],
            'an unset environment variable' => ['!env ZEPHYRUS_TEST_UNSET_LOCALE'],
        ];
    }

    public function testAnAbsentDefaultLocaleIsEnglish(): void
    {
        self::assertSame('en', LocalizationConfig::fromArray([])->locale);
    }

    public function testAnAbsentOrEmptySupportedLocalesAcceptsEveryLocale(): void
    {
        self::assertSame([], LocalizationConfig::fromArray([])->supportedLocales);
        self::assertSame([], LocalizationConfig::fromArray(['supportedLocales' => []])->supportedLocales);
    }

    public function testABlankYamlListItemBesideRealLocalesIsRemoved(): void
    {
        $directory = new CacheDirectory();
        $file = $directory->file('config.yml', "localization:\n  supported_locales:\n    - fr\n    -\n    - EN\n");

        try {
            self::assertSame(['fr', 'en'], Configuration::fromYamlFile($file)->localization->supportedLocales);
        } finally {
            $directory->remove();
        }
    }

    public function testAYamlListOfOnlyBlankItemsIsRefused(): void
    {
        $directory = new CacheDirectory();
        $file = $directory->file('config.yml', "localization:\n  supported_locales:\n    - ~\n");

        try {
            Configuration::fromYamlFile($file);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'localization' field 'supportedLocales' has invalid value array: names no locale, which would accept every locale; name at least one, or remove the key to accept every locale; an unset !env without a default reads as null; add a default, such as !env NAME, fr.",
                $exception->getMessage(),
            );
        } finally {
            $directory->remove();
        }
    }

    public function testAYamlListHoldingACommaSeparatedLocaleIsRefused(): void
    {
        $directory = new CacheDirectory();
        $file = $directory->file('config.yml', "localization:\n  supported_locales:\n    - fr,en\n");

        try {
            Configuration::fromYamlFile($file);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'localization' field 'supportedLocales[0]' has invalid value \"fr,en\": must be one locale; use list items, not a comma inside one item.",
                $exception->getMessage(),
            );
        } finally {
            $directory->remove();
        }
    }

    public function testSurroundingWhitespaceIsIgnoredOnTheTimezoneAndTheFormats(): void
    {
        $config = LocalizationConfig::fromArray([
            'timezone' => ' America/Toronto ',
            'dateFormat' => ' long ',
            'timeFormat' => ' medium ',
            'datetimeFormat' => ' full ',
        ]);

        self::assertSame('America/Toronto', $config->timezone);
        self::assertSame('long', $config->dateFormat);
        self::assertSame('medium', $config->timeFormat);
        self::assertSame('full', $config->datetimeFormat);
    }

    public function testBlankLegacyLocalePathsAreSkippedAndTheRestTrimmed(): void
    {
        self::assertSame('/a', LocalizationConfig::fromArray(['jsonLocalePaths' => ['/a', '']])->localePath);
        self::assertSame('/a', LocalizationConfig::fromArray(['jsonLocalePaths' => ['/a', '  ']])->localePath);
        self::assertSame('/b', LocalizationConfig::fromArray(['jsonLocalePaths' => ['/a', ' /b ']])->localePath);
    }

    public function testFromArrayEmptyCurrencyNormalizesToNull(): void
    {
        $config = LocalizationConfig::fromArray([
            'currency' => '',
        ]);

        self::assertNull($config->currency);
    }

    public function testFromArrayNullLocalePathResultsInNull(): void
    {
        $config = LocalizationConfig::fromArray([
            'localePath' => '',
        ]);

        self::assertNull($config->localePath);
    }

    // ─── Date/Time Format Defaults ────────────────────────────────────

    public function testFromArrayDefaultDateTimeFormats(): void
    {
        $config = LocalizationConfig::fromArray([]);

        self::assertSame('medium', $config->dateFormat);
        self::assertSame('short', $config->timeFormat);
        self::assertSame('medium', $config->datetimeFormat);
    }

    public function testFromArrayCustomDateTimeFormatsWithCamelCaseKeys(): void
    {
        $config = LocalizationConfig::fromArray([
            'dateFormat' => 'yyyy-MM-dd',
            'timeFormat' => 'HH:mm:ss',
            'datetimeFormat' => 'long',
        ]);

        self::assertSame('yyyy-MM-dd', $config->dateFormat);
        self::assertSame('HH:mm:ss', $config->timeFormat);
        self::assertSame('long', $config->datetimeFormat);
    }

    public function testFromArrayCustomDateTimeFormatsWithSnakeCaseKeys(): void
    {
        $config = LocalizationConfig::fromArray([
            'date_format' => 'dd/MM/yyyy',
            'time_format' => 'HH:mm',
            'datetime_format' => 'full',
        ]);

        self::assertSame('dd/MM/yyyy', $config->dateFormat);
        self::assertSame('HH:mm', $config->timeFormat);
        self::assertSame('full', $config->datetimeFormat);
    }

    public function testFromArrayEmptyDateTimeFormatsFallBackToDefaults(): void
    {
        $config = LocalizationConfig::fromArray([
            'date_format' => '',
            'time_format' => '',
            'datetime_format' => '',
        ]);

        self::assertSame('medium', $config->dateFormat);
        self::assertSame('short', $config->timeFormat);
        self::assertSame('medium', $config->datetimeFormat);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedDatePatterns(): iterable
    {
        yield 'date_format in upper case' => ['date_format', 'NONE', 'NONE'];
        yield 'time_format' => ['time_format', 'none', 'none'];
        yield 'datetime_format with spaces' => ['datetime_format', ' none ', 'none'];
    }

    #[DataProvider('refusedDatePatterns')]
    public function testFromArrayRefusesADatePatternThatFormatsNothingNamingTheKey(
        string $key,
        string $value,
        string $shown,
    ): void {
        try {
            LocalizationConfig::fromArray([$key => $value]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'localization' field '$key' has invalid value \"$shown\": "
                . 'it formats nothing. Use short, medium, long, full or an ICU pattern.',
                $exception->getMessage(),
            );
        }
    }

    public function testFromArrayGroupingSeparatorIsNullByDefault(): void
    {
        self::assertNull(LocalizationConfig::fromArray([])->groupingSeparator);
    }

    public function testFromArrayGroupingSeparatorAcceptsCamelAndSnakeCaseKeys(): void
    {
        self::assertSame("\u{202F}", LocalizationConfig::fromArray(['groupingSeparator' => "\u{202F}"])->groupingSeparator);
        self::assertSame("\u{202F}", LocalizationConfig::fromArray(['grouping_separator' => "\u{202F}"])->groupingSeparator);
    }

    public function testFromArrayEmptyGroupingSeparatorIsKeptToDisableGrouping(): void
    {
        self::assertSame('', LocalizationConfig::fromArray(['grouping_separator' => ''])->groupingSeparator);
    }

    public function testFromArrayGroupingSeparatorIsNotTrimmed(): void
    {
        self::assertSame(' ', LocalizationConfig::fromArray(['grouping_separator' => ' '])->groupingSeparator);
    }

    public function testFromArrayRefusesAGroupingSeparatorThatIsNotAString(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("field 'grouping_separator'");

        LocalizationConfig::fromArray(['grouping_separator' => ['x']]);
    }

    public function testFromArrayRefusesALongGroupingSeparator(): void
    {
        $this->expectException(ConfigurationException::class);

        LocalizationConfig::fromArray(['grouping_separator' => 'ABCDE']);
    }

    #[DataProviderExternal(FormatterInputTest::class, 'refusedGroupingSeparators')]
    public function testFromArrayRefusesAGroupingSeparatorOutsideTheList(string $separator): void
    {
        try {
            LocalizationConfig::fromArray(['grouping_separator' => $separator]);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString("field 'grouping_separator'", $exception->getMessage());
            self::assertStringContainsString((string) FormatterInput::groupingSeparatorRefusal($separator), $exception->getMessage());
        }
    }

    #[DataProviderExternal(FormatterInputTest::class, 'acceptedGroupingSeparators')]
    public function testFromArrayAcceptsTheListedGroupingSeparators(string $separator): void
    {
        self::assertSame($separator, LocalizationConfig::fromArray(['grouping_separator' => $separator])->groupingSeparator);
    }

    public function testFromArrayRefusesAGroupingSeparatorThatIsNotValidUtf8(): void
    {
        $this->expectException(ConfigurationException::class);

        LocalizationConfig::fromArray(['grouping_separator' => "\xC3\x28"]);
    }

    #[DataProviderExternal(FormatterInputTest::class, 'invalidCurrencyCodes')]
    public function testFromArrayRefusesACurrencyThatIsNotThreeAsciiLetters(string $currency): void
    {
        try {
            LocalizationConfig::fromArray(['currency' => $currency]);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString("field 'currency'", $exception->getMessage());
            self::assertStringContainsString('three ASCII letters, for example CAD', $exception->getMessage());
            self::assertSame(0, preg_match('/[\p{Cc}\p{Cf}]/u', $exception->getMessage()));
        }
    }

    public function testFromArrayRefusesACurrencyThatIsNotAString(): void
    {
        try {
            LocalizationConfig::fromArray(['currency' => ['CAD']]);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString("field 'currency'", $exception->getMessage());
            self::assertStringContainsString('must be a string', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escapedGroupingSeparators(): iterable
    {
        yield 'NUL byte' => ["A\0B", '"A\\u0000B"'];
        yield 'C1 control' => ["\u{0085}", '"\\u0085"'];
        yield 'invalid UTF-8' => ["\xC3\x28", "\"\u{FFFD}(\""];
    }

    #[DataProvider('escapedGroupingSeparators')]
    public function testTheRefusalMessageEscapesTheGroupingSeparator(string $separator, string $shown): void
    {
        try {
            LocalizationConfig::fromArray(['grouping_separator' => $separator]);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'localization' field 'grouping_separator' has invalid value " . $shown
                . ": must be one of , . ' U+2019, a space, U+00A0, U+202F or U+2009, or empty to turn grouping off.",
                $exception->getMessage(),
            );
        }
    }

    public function testAMisspelledTimezoneIsRefused(): void
    {
        try {
            LocalizationConfig::fromArray(['timezon' => 'America/Toronto']);

            self::fail('A misspelled timezone was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'localization' field 'timezon' is an unknown key: did you mean \"timezone\"?",
                $exception->getMessage(),
            );
        }
    }

    public function testTwoSpellingsOfTheLocaleAreRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Configuration section 'localization' sets both 'locale' and 'default_locale': keep one.");

        LocalizationConfig::fromArray(['locale' => 'fr', 'default_locale' => 'en']);
    }

    public function testTheLocalePathAndTheLegacyKeyTogetherAreRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Configuration section 'localization' sets both 'localePath' and 'jsonLocalePaths': keep one.");

        LocalizationConfig::fromArray(['localePath' => '/app/locale', 'jsonLocalePaths' => ['/legacy/locale']]);
    }
}
