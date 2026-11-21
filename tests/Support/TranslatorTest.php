<?php

declare(strict_types=1);

namespace Habi\Tests\Support;

use Habi\Support\MissingTranslationException;
use Habi\Support\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Translator contract (R33, N9): flat catalogs with {name} placeholders,
 * English fallback for keys missing from the active catalog, unknown
 * locales resolve to English, and strict mode (on for the test suite)
 * turns any missing key into a failure.
 */
final class TranslatorTest extends TestCase
{
    private const CATALOGS = [
        'en' => [
            'greeting' => 'Hello, {name}.',
            'only_en' => 'English only',
            'bags.one' => '{count} bag',
            'bags.other' => '{count} bags',
            'js.copy' => 'Copy',
            'js.copied' => 'Copied',
        ],
        'fil' => [
            'greeting' => 'Kumusta, {name}.',
            'bags.one' => '{count} bag',
            'bags.other' => '{count} na bag',
            'js.copy' => 'Kopyahin',
            'js.copied' => 'Nakopya',
        ],
    ];

    protected function tearDown(): void
    {
        Translator::reset();
        Translator::failOnMissing(true);
    }

    public function testTranslatesWithNamedPlaceholders(): void
    {
        $en = new Translator(self::CATALOGS, 'en');
        $fil = $en->withLocale('fil');

        self::assertSame('en', $en->locale());
        self::assertSame('fil', $fil->locale());
        self::assertSame('Hello, <b>.', $en->t('greeting', ['name' => '<b>']));
        self::assertSame('Kumusta, Ana.', $fil->t('greeting', ['name' => 'Ana']));
        self::assertSame('Kumusta, {name}.', $fil->t('greeting'), 'unknown placeholders stay visible');
    }

    public function testUnknownLocaleFallsBackToEnglish(): void
    {
        self::assertSame('en', Translator::normalize('de'));
        self::assertSame('en', Translator::normalize(['fil']));
        self::assertSame('en', Translator::normalize(null));
        self::assertSame('en', Translator::normalize('FIL '), 'locale codes are exact');
        self::assertSame('fil', Translator::normalize('fil'));
        self::assertSame('en', (new Translator(self::CATALOGS, 'xx'))->locale());
    }

    public function testMissingFilipinoKeyReturnsEnglishOutsideStrictMode(): void
    {
        Translator::failOnMissing(false);
        $fil = new Translator(self::CATALOGS, 'fil');

        self::assertSame('English only', $fil->t('only_en'));
        self::assertSame('no.such.key', $fil->t('no.such.key'), 'a key missing everywhere shows the key');
    }

    public function testMissingKeyFailsInStrictMode(): void
    {
        Translator::failOnMissing(true);
        $fil = new Translator(self::CATALOGS, 'fil');

        $this->expectException(MissingTranslationException::class);
        $this->expectExceptionMessage('only_en (fil)');
        $fil->t('only_en');
    }

    public function testPluralChoosesOneOrOther(): void
    {
        $en = new Translator(self::CATALOGS, 'en');

        self::assertSame('1 bag', $en->plural('bags', 1));
        self::assertSame('0 bags', $en->plural('bags', 0));
        self::assertSame('4 na bag', $en->withLocale('fil')->plural('bags', 4));
    }

    public function testPrefixedReturnsTheJavascriptDictionary(): void
    {
        $fil = new Translator(self::CATALOGS, 'fil');

        self::assertSame(['copy' => 'Kopyahin', 'copied' => 'Nakopya'], $fil->prefixed('js.'));
    }

    public function testCurrentDefaultsToEnglishFromTheRepositoryCatalogs(): void
    {
        Translator::reset();

        self::assertSame('en', Translator::current()->locale());
        self::assertTrue(Translator::current()->has('nav.collection'));

        Translator::setCurrent(Translator::current()->withLocale('fil'));
        self::assertSame('fil', Translator::current()->locale());
        self::assertSame(Translator::current()->t('nav.collection'), Translator::translate('nav.collection'));
    }
}
