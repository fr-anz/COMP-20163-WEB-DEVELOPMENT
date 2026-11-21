<?php

declare(strict_types=1);

namespace Habi\Tests\Support;

use Habi\Support\TranslationExtension;
use Habi\Support\Translator;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Twig API: t() is escaped, t_html() inserts only captured markup
 * unescaped, local_date() uses catalog formats and month names, and
 * i18n_json() cannot close its <script> element.
 */
final class TranslationExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Translator::reset();
    }

    public function testFunctionsFollowTheActiveLocale(): void
    {
        Translator::setCurrent(new Translator([
            'en' => [
                'a' => 'A <b>{x}</b>',
                'link' => 'Go to {link} & {plain}',
                'date.long' => 'j F Y',
                'date.datetime' => 'M j, Y, g:i A',
                'date.month.11' => 'November',
                'date.month_short.11' => 'Nov',
                'js.say' => '</script><b>',
            ],
            'fil' => [
                'a' => 'B',
                'link' => 'Pumunta sa {link} at {plain}',
                'date.long' => 'j F Y',
                'date.datetime' => 'j M Y, g:i A',
                'date.month.11' => 'Nobyembre',
                'date.month_short.11' => 'Nob',
                'js.say' => 'x',
            ],
        ], 'en'));
        $twig = new Environment(new ArrayLoader([
            'page' => "{{ locale() }}|{{ t('a', {x: '<i>'}) }}|{% set l %}<a href=\"/track\">track</a>{% endset %}"
                . "{{ t_html('link', {link: l, plain: '<i>'}) }}|{{ local_date('2026-11-21', 'date.long') }}"
                . "|{{ local_date('2026-11-21 02:30:00 UTC', 'date.datetime', 'Asia/Manila') }}|{{ i18n_json() }}",
        ]), ['autoescape' => 'html']);
        $twig->addExtension(new TranslationExtension());

        self::assertSame(
            'en|A &lt;b&gt;&lt;i&gt;&lt;/b&gt;|Go to <a href="/track">track</a> &amp; &lt;i&gt;|21 November 2026'
            . '|Nov 21, 2026, 10:30 AM|{"say":"\u003C\/script\u003E\u003Cb\u003E"}',
            $twig->render('page')
        );

        Translator::setCurrent(Translator::current()->withLocale('fil'));
        self::assertSame(
            'fil|B|Pumunta sa <a href="/track">track</a> at &lt;i&gt;|21 Nobyembre 2026'
            . '|21 Nob 2026, 10:30 AM|{"say":"x"}',
            $twig->render('page')
        );
    }
}
