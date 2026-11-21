<?php

declare(strict_types=1);

namespace Habi\Support;

use DateTimeImmutable;
use DateTimeZone;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Twig API for the catalogs (R33, N9, DSD "Language"). Every function reads
 * Translator::current(), so it follows the locale LocaleMiddleware chose.
 *
 * - t(key, params = {}): escaped by Twig like any other value.
 * - tn(key, count, params = {}): `key.one` / `key.other` with {count}.
 * - t_html(key, params): the catalog text escaped, then params inserted;
 *   params captured with {% set %}…{% endset %} (Twig markup) are inserted
 *   as-is, anything else is escaped. For sentences that contain a link.
 * - locale(): the active code, `en` or `fil` (for <html lang>).
 * - local_date(value, format_key, timezone = null): PHP date() format read
 *   from the catalog, with month names from date.month.N / date.month_short.N.
 * - i18n_json(prefix = 'js.'): the JavaScript dictionary as JSON for a
 *   <script type="application/json" id="i18n"> block (`<`, `>`, `&`, quotes hex-escaped).
 */
final class TranslationExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', static fn (string $key, array $params = []): string =>
                Translator::current()->t($key, $params)),
            new TwigFunction('tn', static fn (string $key, int $count, array $params = []): string =>
                Translator::current()->plural($key, $count, $params)),
            new TwigFunction('t_html', self::html(...), ['is_safe' => ['html']]),
            new TwigFunction('locale', static fn (): string => Translator::current()->locale()),
            new TwigFunction('local_date', self::date(...)),
            new TwigFunction('i18n_json', self::json(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function html(string $key, array $params = []): string
    {
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['{' . $name . '}'] = $value instanceof Markup ? (string) $value : $escape((string) $value);
        }

        return strtr($escape(Translator::current()->t($key)), $replacements);
    }

    public static function date(string $value, string $formatKey, ?string $timezone = null): string
    {
        $translator = Translator::current();
        $date = new DateTimeImmutable($value);
        if ($timezone !== null) {
            $date = $date->setTimezone(new DateTimeZone($timezone));
        }
        $format = $translator->t($formatKey);
        $month = (int) $date->format('n');
        $out = '';
        for ($i = 0, $length = strlen($format); $i < $length; $i++) {
            $char = $format[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $out .= $format[++$i];
            } elseif ($char === 'F') {
                $out .= $translator->t('date.month.' . $month);
            } elseif ($char === 'M') {
                $out .= $translator->t('date.month_short.' . $month);
            } else {
                $out .= $date->format($char);
            }
        }

        return $out;
    }

    public static function json(string $prefix = 'js.'): string
    {
        return (string) json_encode(
            Translator::current()->prefixed($prefix),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
