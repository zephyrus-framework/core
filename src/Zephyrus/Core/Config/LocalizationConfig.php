<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use InvalidArgumentException;
use Zephyrus\Exceptions\MessageValue;
use Zephyrus\Formatting\FormatterInput;
use Zephyrus\Localization\LocaleTag;

/**
 * Immutable localization bootstrap config.
 *
 * YAML keys (aliases accepted where listed, one spelling per setting; any other key is refused):
 * - locale (defaultLocale, default_locale): translator default locale, lower-cased, so 'fr-CA' is
 *   stored as 'fr-ca'. Default 'en'.
 * - supportedLocales (supported_locales): negotiation allowlist. Trimmed, lower-cased, null and blank
 *   entries dropped. Only [] or no key accepts every locale. Default [].
 *   REJECTED: a declared null (an unset !env without default); a value that is not a string or a list;
 *   an entry that is not a string or null; an entry with a comma; a non-blank entry that is not a locale
 *   tag (fr, pt-BR, at most 35 bytes); a non-empty declaration that names no locale.
 * - localePath (locale_path, jsonLocalePaths, json_locale_paths): one directory of locales.
 *   The legacy array form keeps its last non-empty entry. Default null.
 * - timezone: applied with date_default_timezone_set() by ApplicationBuilder. Default 'UTC'.
 * - currency: default currency code for Formatter::money(), three ASCII letters, trimmed; '' means
 *   none. Default null.
 * - dateFormat (date_format), timeFormat (time_format), datetimeFormat (datetime_format): ICU
 *   pattern or preset for Formatter::date(), time() and datetime(). Defaults 'medium', 'short', 'medium'.
 * - groupingSeparator (grouping_separator): thousands separator. Null keeps the locale default,
 *   '' disables grouping. Otherwise one of `,` `.` `'` U+2019, a space, U+00A0, U+202F or U+2009.
 *   In a right-to-left locale, or inside right-to-left text, only `,` `.` U+00A0 and U+202F keep the
 *   groups in order; a space, U+2009, `'` or U+2019 can reverse them.
 */
final readonly class LocalizationConfig
{
    /** Keys that hold the legacy array form of localePath. */
    private const array LEGACY_LOCALE_PATH_KEYS = ['jsonLocalePaths', 'json_locale_paths'];

    /** Accepted keys per property, preferred first; any other key is refused. */
    private const array SPELLINGS = [
        'locale' => ['locale', 'defaultLocale', 'default_locale'],
        'supportedLocales' => ['supportedLocales', 'supported_locales'],
        'localePath' => ['localePath', 'locale_path', ...self::LEGACY_LOCALE_PATH_KEYS],
        'timezone' => ['timezone'],
        'currency' => ['currency'],
        'dateFormat' => ['dateFormat', 'date_format'],
        'timeFormat' => ['timeFormat', 'time_format'],
        'datetimeFormat' => ['datetimeFormat', 'datetime_format'],
        'groupingSeparator' => ['groupingSeparator', 'grouping_separator'],
    ];

    private const string NAMES_NO_LOCALE = 'names no locale, which would accept every locale; name at least one, '
        . 'or remove the key to accept every locale';

    private const string NOT_A_LOCALE_TAG = 'must be a locale tag, such as fr or pt-BR';

    /**
     * @param string[] $supportedLocales
     */
    public function __construct(
        public string $locale,
        public array $supportedLocales,
        public ?string $localePath = null,
        public string $timezone = 'UTC',
        public ?string $currency = null,
        public string $dateFormat = 'medium',
        public string $timeFormat = 'short',
        public string $datetimeFormat = 'medium',
        public ?string $groupingSeparator = null,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     * @throws ConfigurationException if a key is unknown or a setting is written in two spellings.
     *         If locale or timezone is blank.
     *         If supportedLocales is declared null, is not a string or a list, or names no locale.
     *         If a supportedLocales entry is neither a string nor null, contains a comma or is not a locale tag.
     *         If currency, grouping_separator, date_format, time_format or datetime_format is invalid.
     */
    public static function fromArray(array $values): self
    {
        $keys = ConfigKeys::read('localization', $values, self::SPELLINGS);

        $locale = trim((string) ($keys->value('locale') ?? 'en'));
        $declaredLocales = $keys->value('supportedLocales');
        if ($declaredLocales === null && $keys->has('supportedLocales')) {
            throw ConfigurationException::invalidValue(
                'localization',
                'supportedLocales',
                null,
                self::NAMES_NO_LOCALE . '; ' . ConfigBoolean::unsetEnvHint('fr'),
            );
        }

        $localePath = self::resolveLocalePath($keys);

        $timezone = trim((string) ($keys->value('timezone') ?? 'UTC'));
        $currency = self::resolveCurrency($keys->value('currency'));
        $dateFormat = trim((string) ($keys->value('dateFormat') ?? 'medium'));
        $timeFormat = trim((string) ($keys->value('timeFormat') ?? 'short'));
        $datetimeFormat = trim((string) ($keys->value('datetimeFormat') ?? 'medium'));
        $groupingSeparator = self::resolveGroupingSeparator($keys->value('groupingSeparator'));

        $dateFormat = self::resolveDatePattern('date_format', $dateFormat, 'medium');
        $timeFormat = self::resolveDatePattern('time_format', $timeFormat, 'short');
        $datetimeFormat = self::resolveDatePattern('datetime_format', $datetimeFormat, 'medium');

        if ($locale === '') {
            throw ConfigurationException::invalidValue('localization', 'locale', $locale, 'must be non-empty');
        }

        if ($timezone === '') {
            throw ConfigurationException::invalidValue('localization', 'timezone', $timezone, 'must be non-empty');
        }

        $supportedLocales = self::resolveSupportedLocales($declaredLocales);

        $localePath = ($localePath !== null && $localePath !== '') ? $localePath : null;

        return new self(
            locale: strtolower($locale),
            supportedLocales: $supportedLocales,
            localePath: $localePath,
            timezone: $timezone,
            currency: $currency,
            dateFormat: $dateFormat,
            timeFormat: $timeFormat,
            datetimeFormat: $datetimeFormat,
            groupingSeparator: $groupingSeparator,
        );
    }

    /**
     * Replace a blank pattern with its default, and refuse one that formats nothing.
     *
     * @throws ConfigurationException if the pattern is 'none' in any case.
     */
    private static function resolveDatePattern(string $field, string $pattern, string $default): string
    {
        if ($pattern === '') {
            return $default;
        }

        $refusal = FormatterInput::datePatternRefusal($pattern);
        if ($refusal !== null) {
            throw ConfigurationException::invalidValue(
                'localization',
                $field,
                $pattern,
                $refusal . '. ' . FormatterInput::DATE_PATTERN_RULE,
            );
        }

        return $pattern;
    }

    /**
     * Validate the grouping separator with FormatterInput. The locale-dependent checks run in Formatter.
     *
     * @throws ConfigurationException if the value is not a string, or not '' and refused by FormatterInput.
     */
    private static function resolveGroupingSeparator(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (!is_string($value)) {
            throw ConfigurationException::invalidValue('localization', 'grouping_separator', $value, 'must be a string');
        }

        $refusal = FormatterInput::groupingSeparatorRefusal($value);
        if ($refusal !== null) {
            throw ConfigurationException::invalidValue('localization', 'grouping_separator', $value, $refusal);
        }

        return $value;
    }

    /**
     * Validate the currency code after trimming; '' means none.
     *
     * @throws ConfigurationException if the value is not a string or the code is not three ASCII letters.
     */
    private static function resolveCurrency(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw ConfigurationException::invalidValue('localization', 'currency', $value, 'must be a string');
        }

        $currency = trim($value);
        if ($currency === '') {
            return null;
        }

        if (!FormatterInput::isCurrencyCode($currency)) {
            throw ConfigurationException::invalidValue('localization', 'currency', $currency, FormatterInput::CURRENCY_CODE_RULE);
        }

        return $currency;
    }

    /**
     * Trim and lower-case each entry, and drop the null and blank ones.
     *
     * @return list<string>
     * @throws ConfigurationException if the value is not null, a string or a list, or the list is refused.
     */
    private static function resolveSupportedLocales(mixed $declared): array
    {
        if ($declared === null) {
            return [];
        }

        if (!is_string($declared) && !is_array($declared)) {
            throw ConfigurationException::invalidValue(
                'localization',
                'supportedLocales',
                $declared,
                'must be a string or a list of strings',
            );
        }

        $entries = (array) $declared;
        $refusal = self::supportedLocalesRefusal($entries);
        if ($refusal !== null) {
            $wholeValue = $refusal['index'] === null || is_string($declared);
            $field = $wholeValue ? 'supportedLocales' : 'supportedLocales[' . $refusal['index'] . ']';
            $value = $wholeValue ? $declared : $entries[$refusal['index']];
            $reason = $refusal['index'] === null
                ? $refusal['reason'] . '; ' . ConfigBoolean::unsetEnvHint('fr')
                : $refusal['reason'];
            throw ConfigurationException::invalidValue('localization', $field, $value, $reason);
        }

        return self::normalizeSupportedLocales($entries);
    }

    /**
     * Trim and lower-case each entry, and drop the null and blank ones.
     *
     * @internal Shared with ApplicationBuilder and Application.
     * @param array<array-key, mixed> $entries entries already accepted by supportedLocalesRefusal()
     * @return list<string>
     */
    public static function normalizeSupportedLocales(array $entries): array
    {
        $locales = [];
        foreach ($entries as $locale) {
            $normalized = strtolower(trim((string) $locale));
            if ($normalized !== '') {
                $locales[] = $normalized;
            }
        }

        return $locales;
    }

    /**
     * Refuse a supported locales argument that supportedLocalesRefusal() refuses, naming the method and parameter.
     *
     * @internal Shared with ApplicationBuilder and Application.
     * @param array<array-key, mixed> $entries
     * @throws InvalidArgumentException if the list is refused, null entries included.
     */
    public static function assertSupportedLocalesArgument(string $method, string $parameter, array $entries): void
    {
        $refusal = self::supportedLocalesRefusal($entries, nullIsEntry: true);
        if ($refusal === null) {
            return;
        }

        throw new InvalidArgumentException($refusal['index'] === null
            ? $method . '(): $' . $parameter . ' names no locale, which would accept every locale; '
                . 'pass at least one, or an empty list to accept every locale.'
            : $method . '(): $' . $parameter . '[' . self::shownIndex($refusal['index']) . '] '
                . MessageValue::describe($entries[$refusal['index']])
                . ' is refused: ' . $refusal['reason'] . '.');
    }

    /**
     * An entry index as a refusal shows it: a key over 64 bytes is quoted and cut, a shorter one is escaped as is.
     */
    private static function shownIndex(int|string $index): string
    {
        if (is_int($index)) {
            return (string) $index;
        }

        return strlen($index) > 64 ? MessageValue::quote($index) : MessageValue::escapeControls($index);
    }

    /**
     * The first reason a supported locales list is refused, with the index of the entry or null for the whole list.
     *
     * @param array<array-key, mixed> $entries
     * @param bool $nullIsEntry whether a null entry is refused as an entry that is not a string, instead of ignored
     * @return array{index: array-key|null, reason: string}|null
     */
    private static function supportedLocalesRefusal(array $entries, bool $nullIsEntry = false): ?array
    {
        $namesALocale = false;
        foreach ($entries as $i => $locale) {
            if ($locale === null && !$nullIsEntry) {
                continue;
            }

            if (!is_string($locale)) {
                return ['index' => $i, 'reason' => 'each entry must be a string'];
            }

            if (str_contains($locale, ',')) {
                return ['index' => $i, 'reason' => 'must be one locale; use list items, not a comma inside one item'];
            }

            $tag = trim($locale);
            if ($tag !== '' && (strlen($tag) > LocaleTag::MAX_LENGTH || !LocaleTag::isWellFormed($tag))) {
                return ['index' => $i, 'reason' => self::NOT_A_LOCALE_TAG];
            }

            $namesALocale = $namesALocale || $tag !== '';
        }

        if ($entries !== [] && !$namesALocale) {
            return ['index' => null, 'reason' => self::NAMES_NO_LOCALE];
        }

        return null;
    }

    /**
     * Resolve the locale path from new or legacy config keys.
     */
    private static function resolveLocalePath(ConfigKeys $keys): ?string
    {
        if (!in_array($keys->key('localePath'), self::LEGACY_LOCALE_PATH_KEYS, true)) {
            $path = trim((string) ($keys->value('localePath') ?? ''));
            return $path !== '' ? $path : null;
        }

        $legacyPaths = $keys->value('localePath');
        if (is_array($legacyPaths)) {
            $filtered = array_values(array_filter(array_map(static function (mixed $p): string {
                return trim((string) $p);
            }, $legacyPaths), static fn (string $p): bool => $p !== ''));
            return $filtered !== [] ? end($filtered) : null;
        }

        return null;
    }
}
