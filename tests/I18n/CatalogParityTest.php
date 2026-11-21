<?php

declare(strict_types=1);

namespace Habi\Tests\I18n;

use PHPUnit\Framework\TestCase;

/**
 * N9: lang/en.php and lang/fil.php have identical key sets, every value is a
 * non-empty string with the same {placeholders}, and every literal key used
 * in templates, PHP and the JavaScript dictionary exists in the catalogs.
 */
final class CatalogParityTest extends TestCase
{
    public function testCatalogsHaveIdenticalKeys(): void
    {
        $en = self::catalog('en');
        $fil = self::catalog('fil');

        $missingInFil = array_values(array_diff(array_keys($en), array_keys($fil)));
        $extraInFil = array_values(array_diff(array_keys($fil), array_keys($en)));

        self::assertSame(
            [[], []],
            [$missingInFil, $extraInFil],
            "Catalog keys differ.\nMissing from lang/fil.php: " . implode(', ', $missingInFil)
            . "\nMissing from lang/en.php (extra in fil): " . implode(', ', $extraInFil)
        );
    }

    public function testValuesAreNonEmptyStringsWithMatchingPlaceholders(): void
    {
        $en = self::catalog('en');
        $fil = self::catalog('fil');
        $problems = [];
        foreach ($en as $key => $value) {
            foreach (['en' => $value, 'fil' => $fil[$key] ?? ''] as $locale => $text) {
                if (!is_string($text) || trim($text) === '') {
                    $problems[] = "$key ($locale) is empty or not a string";
                }
            }
            if (is_string($value) && is_string($fil[$key] ?? null)) {
                $a = self::placeholders($value);
                $b = self::placeholders($fil[$key]);
                if ($a !== $b) {
                    $problems[] = "$key placeholders differ: en {" . implode('}{', $a)
                        . "} fil {" . implode('}{', $b) . '}';
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    public function testEveryReferencedKeyExists(): void
    {
        $en = self::catalog('en');
        $root = habi_repo_root();
        $files = array_merge(
            glob($root . '/views/{storefront,layouts,macros}/*.twig', GLOB_BRACE) ?: [],
            self::phpFiles($root . '/src')
        );
        $missing = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            // t('key'), tn('key', n), t_html('key'), Translator::translate('key'), ->t('key')
            preg_match_all(
                "/(?:\\bt|\\btn|\\bt_html|::translate|->t|->plural)\\(\\s*'([a-z0-9_]+(?:\\.[a-z0-9_]+)+)'/",
                $source,
                $matches
            );
            foreach ($matches[1] as $key) {
                $exists = isset($en[$key]) || (isset($en[$key . '.one']) && isset($en[$key . '.other']));
                if (!$exists) {
                    $missing[] = substr($file, strlen($root) + 1) . ': ' . $key;
                }
            }
        }

        self::assertSame([], array_values(array_unique($missing)), 'Keys referenced but missing from lang/en.php');
    }

    public function testJavascriptKeysUsedExistInTheDictionary(): void
    {
        $en = self::catalog('en');
        $missing = [];
        foreach (HardcodedStringsTest::customerScripts() as $file) {
            preg_match_all("/\\bi18n\\(\\s*'([a-z0-9_.]+)'/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $key) {
                if (!isset($en['js.' . $key])) {
                    $missing[] = basename($file) . ': js.' . $key;
                }
            }
        }

        self::assertSame([], array_values(array_unique($missing)), 'JavaScript keys missing from lang/en.php');
    }

    /** @return array<string, mixed> */
    private static function catalog(string $locale): array
    {
        $catalog = require habi_repo_root() . '/lang/' . $locale . '.php';
        self::assertIsArray($catalog, "lang/$locale.php must return an array");

        return $catalog;
    }

    /** @return list<string> */
    private static function placeholders(string $text): array
    {
        preg_match_all('/\{([a-z0-9_]+)\}/', $text, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
