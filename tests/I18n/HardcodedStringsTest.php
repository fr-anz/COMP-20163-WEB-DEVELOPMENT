<?php

declare(strict_types=1);

namespace Habi\Tests\I18n;

use PHPUnit\Framework\TestCase;

/**
 * N9 leftover scan: customer-facing templates (views/storefront, layouts,
 * macros) and customer JavaScript must take their words from the catalogs.
 *
 * A pragmatic heuristic, not a parser:
 * - Twig: after removing comments, <script>/<style>/<svg> bodies and Twig
 *   tags, any text node or aria-label/title/placeholder/alt/content value
 *   with two or more letters is reported; inside Twig tags, string literals
 *   that look like prose (a capitalized word, or a lowercase word picked by
 *   a ternary such as `? 'item' : 'items'`) are reported too.
 * - JavaScript: after removing comments, string literals with a capitalized
 *   word (alone or in prose) or three lowercase words in a row are reported,
 *   plus lowercase words picked by a ternary; developer errors (new
 *   Error/TypeError) and the identifiers in JS_ALLOWED pass.
 * Brand marks, data and catalog keys pass; anything else needs a key.
 */
final class HardcodedStringsTest extends TestCase
{
    /** Literal text that is not language: the split wordmark, og:type and boolean attribute values. */
    private const TWIG_ALLOWED = ['hab', 'ı', 'website', 'product', 'profile', 'article', 'noindex',
        'width=device-width, initial-scale=1', 'summary_large_image', 'Asia/Manila', ' UTC', 'true', 'false'];

    /** DOM/API identifiers that look like words. */
    private const JS_ALLOWED = ['Tab', 'Escape', 'Enter', 'Home', 'End', 'ArrowLeft', 'ArrowRight', 'ArrowUp',
        'ArrowDown', 'IntersectionObserver', 'ResizeObserver', 'Accept', 'Content-Type', 'X-Requested-With',
        'WebGLUnavailableError', 'Error', 'TypeError', 'AbortError', 'minus', 'plus', 'true', 'false'];

    /** @return list<string> */
    public static function customerScripts(): array
    {
        $files = glob(habi_repo_root() . '/public/assets/js/*.js') ?: [];

        return array_values(array_filter($files, static fn (string $f): bool => basename($f) !== 'admin.js'));
    }

    public function testCustomerTemplatesHaveNoHardCodedText(): void
    {
        $root = habi_repo_root();
        $found = [];
        foreach (glob($root . '/views/{storefront,layouts,macros}/*.twig', GLOB_BRACE) ?: [] as $file) {
            foreach (self::twigLeftovers((string) file_get_contents($file)) as $text) {
                $found[] = substr($file, strlen($root) + 1) . ': ' . $text;
            }
        }

        self::assertSame([], $found, "Hard-coded customer text (move it to lang/*.php):\n" . implode("\n", $found));
    }

    public function testCustomerJavascriptHasNoHardCodedText(): void
    {
        $found = [];
        foreach (self::customerScripts() as $file) {
            foreach (self::jsLeftovers((string) file_get_contents($file)) as $text) {
                $found[] = basename($file) . ': ' . $text;
            }
        }

        self::assertSame([], $found, "Hard-coded customer text (pass it via #i18n):\n" . implode("\n", $found));
    }

    public function testTheHeuristicCatchesKnownShapes(): void
    {
        self::assertSame(
            ['Free', 'item', 'Close menu', 'Search', 'Your bag'],
            self::twigLeftovers(
                "{# Comment text #}<h1>Your bag</h1><button aria-label=\"Close menu\"></button>"
                . "<input placeholder=\"Search\">{{ x ? 'Free' : t('a.b') }}{{ n == 1 ? 'item' : t('c.d') }}"
                . '<span>{{ t(\'cart.title\') }} — {{ app_name }}</span><script>var a = "Ignored";</script>'
            )
        );
        self::assertSame(
            ['Added to bag', 'please try again later'],
            self::jsLeftovers(
                "// Comment Text\nshow('Added to bag');\nvar k = 'Tab';\nfail('please try again later');\n"
                . "el.className = 'flex items-start gap-3';\nthrow new Error('Viewer Broke');\nt(i18n('cart.added'));"
            )
        );
    }

    /** @return list<string> */
    private static function twigLeftovers(string $source): array
    {
        $found = [];
        $source = (string) preg_replace('/\{#.*?#\}/s', '', $source);
        $source = (string) preg_replace('#<(script|style|svg)\b[^>]*>.*?</\1>#si', '', $source);

        preg_match_all('/\{\{.*?\}\}|\{%.*?%\}/s', $source, $tags);
        foreach ($tags[0] as $tag) {
            preg_match_all("/'([^'\\\\]*)'|\"([^\"\\\\]*)\"/", $tag, $literals, PREG_SET_ORDER);
            foreach ($literals as $literal) {
                $text = $literal[2] ?? $literal[1];
                $text = $text === '' ? $literal[1] : $text;
                if (in_array($text, self::TWIG_ALLOWED, true)) {
                    continue;
                }
                if (preg_match('/[A-Z][a-z]/', $text) === 1 && preg_match('/^[a-z0-9_.\/-]+$/i', $text) !== 1) {
                    $found[] = $text;
                } elseif (preg_match('/^[A-Z][a-z]+$/', $text) === 1) {
                    $found[] = $text;
                }
            }
            preg_match_all("/\\?\\s*'(\\s*[a-z]+(?: [a-z]+)*)'\\s*:/", $tag, $ternaries);
            foreach ($ternaries[1] as $text) {
                if (!in_array($text, self::TWIG_ALLOWED, true)) {
                    $found[] = $text;
                }
            }
        }

        $markup = (string) preg_replace('/\{\{.*?\}\}|\{%.*?%\}/s', '', $source);
        preg_match_all(
            '/\s(?:aria-label|aria-roledescription|title|placeholder|alt|content|data-alt|label)="([^"]*)"/',
            $markup,
            $attributes
        );
        foreach ($attributes[1] as $value) {
            $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $isPath = preg_match('~^(?:#|/)~', $value) === 1;
            if (preg_match('/[A-Za-z]{2,}/', $value) === 1 && !$isPath && !in_array($value, self::TWIG_ALLOWED, true)) {
                $found[] = $value;
            }
        }

        $text = (string) preg_replace('/<[^>]*>/', "\n", $markup);
        foreach (preg_split('/\n+/', $text) ?: [] as $line) {
            $line = trim(html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (preg_match('/[A-Za-z]{2,}/', $line) === 1 && !in_array($line, self::TWIG_ALLOWED, true)) {
                $found[] = $line;
            }
        }

        return $found;
    }

    /** @return list<string> */
    private static function jsLeftovers(string $source): array
    {
        $found = [];
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
        $source = (string) preg_replace('#(^|[^:\'"\\\\])//[^\n]*#', '$1', $source);
        foreach (preg_split('/\n/', $source) ?: [] as $line) {
            if (preg_match('/\b(?:new\s+)?(?:Type)?Error\(|console\./', $line) === 1) {
                continue;
            }
            $pattern = "/'((?:[^'\\\\\\n]|\\\\.)*)'|\"((?:[^\"\\\\\\n]|\\\\.)*)\"|`([^`]*)`/";
            preg_match_all($pattern, $line, $literals, PREG_SET_ORDER);
            foreach ($literals as $literal) {
                $text = (string) ($literal[3] ?? '') !== '' ? $literal[3]
                    : ((string) ($literal[2] ?? '') !== '' ? $literal[2] : $literal[1]);
                if (in_array($text, self::JS_ALLOWED, true)) {
                    continue;
                }
                $prose = preg_match('/(?:^|[\s(])[A-Z][a-z]+(?:[\s,.;:!?\x{2014}\x{2026}]|$)/u', $text) === 1
                    && preg_match('/[<>=#\[\]{}]|^[\w-]+$/', $text) !== 1;
                $words = preg_match('/(?:^|\s)[a-z]{2,} [a-z]{2,} [a-z]{2,}(?:\s|[.,;]|$)/', $text) === 1
                    && preg_match('/[-:\[\]=#>(]/', $text) !== 1;
                $word = preg_match('/^[A-Z][a-z]+(?:\\\\u2026|\x{2026})?$/u', $text) === 1;
                if ($prose || $words || $word || preg_match('/^[A-Z][a-z]+ [a-z]/', $text) === 1) {
                    $found[] = $text;
                }
            }
            preg_match_all("/\\?\\s*'(\\s*[a-z]+(?: [a-z]+)*)'\\s*:/", $line, $ternaries);
            foreach ($ternaries[1] as $text) {
                if (!in_array($text, self::JS_ALLOWED, true)) {
                    $found[] = $text;
                }
            }
        }

        return array_values(array_unique($found));
    }
}
