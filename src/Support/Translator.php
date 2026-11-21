<?php

declare(strict_types=1);

namespace Habi\Support;

/**
 * Customer-facing strings (R33, N9). Catalogs are lang/{en,fil}.php: flat
 * `key => string` arrays whose values may contain {name} placeholders,
 * replaced verbatim by t(); escaping is the caller's job (Twig autoescapes).
 *
 * English is the default and the fallback: a key missing from the active
 * catalog returns the English string, and a key missing everywhere returns
 * the key itself. In strict mode (enabled by tests/bootstrap.php) any key
 * missing from the active catalog throws MissingTranslationException.
 *
 * Plurals use `key.one` / `key.other` with a {count} placeholder. Keys under
 * `js.` form the JavaScript dictionary (prefixed()), rendered into the
 * #i18n JSON block by the base layout.
 *
 * The request's translator is held statically (current()), set by
 * LocaleMiddleware once per request, so services and the Twig extension
 * share one locale without threading it through every constructor.
 */
final class Translator
{
    public const DEFAULT_LOCALE = 'en';

    /** Supported locales; the codes are also the <html lang> values (R26). */
    public const LOCALES = ['en', 'fil'];

    private static ?self $current = null;
    private static bool $strict = false;

    /** @var array<string, array<string, array<string, string>>> */
    private static array $loaded = [];

    private readonly string $locale;

    /**
     * @param array<string, array<string, string>> $catalogs locale => (key => string); 'en' is required
     */
    public function __construct(
        private readonly array $catalogs,
        string $locale = self::DEFAULT_LOCALE,
    ) {
        $this->locale = self::normalize($locale);
    }

    /**
     * Loads lang/{locale}.php for every supported locale from a directory.
     * Catalog files are read once per process.
     */
    public static function fromDirectory(string $directory, string $locale = self::DEFAULT_LOCALE): self
    {
        if (!isset(self::$loaded[$directory])) {
            $catalogs = [];
            foreach (self::LOCALES as $code) {
                $file = $directory . '/' . $code . '.php';
                $catalog = is_file($file) ? require $file : [];
                $catalogs[$code] = is_array($catalog) ? $catalog : [];
            }
            self::$loaded[$directory] = $catalogs;
        }

        return new self(self::$loaded[$directory], $locale);
    }

    /** The request's translator; English from the repository catalogs until LocaleMiddleware runs. */
    public static function current(): self
    {
        return self::$current ??= self::fromDirectory(habi_repo_root() . '/lang');
    }

    public static function setCurrent(self $translator): void
    {
        self::$current = $translator;
    }

    /** Forgets the request translator (tests). */
    public static function reset(): void
    {
        self::$current = null;
    }

    /** Strict mode: missing keys throw instead of falling back (tests only). */
    public static function failOnMissing(bool $strict): void
    {
        self::$strict = $strict;
    }

    /** Shorthand for current()->t(), used by services for customer messages. */
    public static function translate(string $key, array $params = []): string
    {
        return self::current()->t($key, $params);
    }

    public static function isSupported(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, self::LOCALES, true);
    }

    /** A supported locale code, or English for anything else. */
    public static function normalize(mixed $locale): string
    {
        return self::isSupported($locale) ? $locale : self::DEFAULT_LOCALE;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function withLocale(string $locale): self
    {
        return new self($this->catalogs, $locale);
    }

    /** Whether the English catalog defines the key. */
    public function has(string $key): bool
    {
        return isset($this->catalogs[self::DEFAULT_LOCALE][$key]);
    }

    /**
     * @param array<string, scalar|\Stringable|null> $params
     */
    public function t(string $key, array $params = []): string
    {
        $text = $this->catalogs[$this->locale][$key] ?? null;
        if (!is_string($text)) {
            if (self::$strict) {
                throw new MissingTranslationException(sprintf('Missing translation: %s (%s)', $key, $this->locale));
            }
            $fallback = $this->catalogs[self::DEFAULT_LOCALE][$key] ?? null;
            $text = is_string($fallback) ? $fallback : $key;
        }
        if ($params === []) {
            return $text;
        }
        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }

        return strtr($text, $replacements);
    }

    /**
     * `key.one` for a count of 1, else `key.other`; {count} is filled in.
     *
     * @param array<string, scalar|\Stringable|null> $params
     */
    public function plural(string $key, int $count, array $params = []): string
    {
        return $this->t($key . ($count === 1 ? '.one' : '.other'), ['count' => $count] + $params);
    }

    /**
     * Every active-locale string whose key starts with the prefix, keyed
     * without it (the JavaScript dictionary, prefix `js.`).
     *
     * @return array<string, string>
     */
    public function prefixed(string $prefix): array
    {
        $strings = [];
        foreach (array_keys($this->catalogs[self::DEFAULT_LOCALE] ?? []) as $key) {
            if (str_starts_with($key, $prefix)) {
                $strings[substr($key, strlen($prefix))] = $this->t($key);
            }
        }

        return $strings;
    }
}
