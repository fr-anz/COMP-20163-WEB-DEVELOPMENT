<?php

declare(strict_types=1);

namespace Habi\Support;

/**
 * Plain-text rules for admin-managed content (PRD R43, N2). Stories and
 * article bodies are stored as plain text with paragraphs separated by one
 * blank line ("\n\n"); HTML is refused on save, never stripped silently, and
 * templates always escape. Journal sources are one source per line
 * ("Author. Title. Publisher, Year. URL"), and any URL must be http(s).
 */
final class PlainText
{
    /** Normalized multi-paragraph text: LF line ends, no control characters, at most one blank line in a row. */
    public static function paragraphs(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[^\P{C}\n]/u', '', $value);
        $lines = array_map(static fn (string $line): string => trim($line), explode("\n", $value));
        $value = (string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));

        return trim($value);
    }

    /** True when the text contains something that looks like an HTML tag or comment. */
    public static function hasMarkup(string $value): bool
    {
        return preg_match('/<\s*[a-z!\/?]/i', $value) === 1;
    }

    /**
     * Normalizes a sources list and reports the first invalid line.
     *
     * @return array{value: string, error: ?string}
     */
    public static function sources(string $value): array
    {
        $lines = array_values(array_filter(
            array_map(
                static fn (string $line): string => trim((string) preg_replace('/\s+/u', ' ', $line)),
                explode("\n", str_replace(["\r\n", "\r"], "\n", $value))
            ),
            static fn (string $line): bool => $line !== ''
        ));
        foreach ($lines as $index => $line) {
            if (self::hasMarkup($line)) {
                return ['value' => implode("\n", $lines), 'error' => sprintf(
                    'Source %d contains HTML. Use plain text: Author. Title. Publisher, Year. URL',
                    $index + 1
                )];
            }
            foreach (explode(' ', $line) as $token) {
                // URL-like: anything with "://" or a script/data scheme; "Title: Subtitle" is text.
                if (
                    !str_contains($token, '://')
                    && preg_match('/^(javascript|vbscript|data|file):/i', $token) !== 1
                ) {
                    continue;
                }
                $url = rtrim($token, '.,;)');
                $valid = preg_match('#^https?://[^\s<>"]+$#i', $url) === 1
                    && filter_var($url, FILTER_VALIDATE_URL) !== false
                    && (string) parse_url($url, PHP_URL_HOST) !== '';
                if (!$valid) {
                    return ['value' => implode("\n", $lines), 'error' => sprintf(
                        'Source %d has a link that is not a full http:// or https:// address.',
                        $index + 1
                    )];
                }
            }
        }

        return ['value' => implode("\n", $lines), 'error' => null];
    }
}
