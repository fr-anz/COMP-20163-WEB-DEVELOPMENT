<?php

declare(strict_types=1);

namespace Habi\Tests\Support;

use Habi\Support\PlainText;
use PHPUnit\Framework\TestCase;

/**
 * Content sanitization for admin-managed text (R43, N2): stored values are
 * plain text with "\n\n" between paragraphs, HTML is refused, and journal
 * sources are one non-empty line each with only http(s) URLs.
 */
final class PlainTextTest extends TestCase
{
    public function testParagraphsNormalizeLineEndingsAndBlankLines(): void
    {
        self::assertSame(
            "First paragraph\nsame paragraph.\n\nSecond paragraph.",
            PlainText::paragraphs("  First paragraph  \r\nsame paragraph.\r\n\r\n\r\n\n  Second paragraph.  \n")
        );
        self::assertSame('', PlainText::paragraphs(" \n\t\n "));
        self::assertSame('Tab and bell removed', PlainText::paragraphs("Tab and\x07 bell removed"));
    }

    public function testMarkupIsDetected(): void
    {
        $markup = ['<b>bold</b>', 'a <script>x</script>', '<!-- c -->', '</p>', '<img src=x onerror=1>', '< a>'];
        foreach ($markup as $html) {
            self::assertTrue(PlainText::hasMarkup($html), $html);
        }
        foreach (['3 < 5 and 7 > 2', 'Fish & chips', 'a<5', '&lt;b&gt;'] as $text) {
            self::assertFalse(PlainText::hasMarkup($text), $text);
        }
    }

    public function testSourcesKeepOneNonEmptyLineEachAndAcceptHttpUrls(): void
    {
        $result = PlainText::sources(
            "  National Museum. Weaving. NM, 2020. https://example.org/a  \r\n\r\n"
            . "Author Name. Book Title. Press, 1999.\n"
        );
        self::assertNull($result['error']);
        self::assertSame(
            "National Museum. Weaving. NM, 2020. https://example.org/a\nAuthor Name. Book Title. Press, 1999.",
            $result['value']
        );
        self::assertSame(['value' => '', 'error' => null], PlainText::sources(''));
        self::assertNull(PlainText::sources('Author. Weaving: A History. Press, 2001.')['error']);
    }

    public function testSourcesRefuseOtherSchemesAndMarkup(): void
    {
        foreach (
            [
                'Bad. Link. X, 2020. javascript:alert(1)',
                'Bad. Link. X, 2020. ftp://example.org/file',
                'Bad. Link. X, 2020. data:text/html;base64,AAAA',
                'Bad. Link. X, 2020. http://',
                'Bad. <a href="https://x.test">Link</a>',
            ] as $line
        ) {
            self::assertNotNull(PlainText::sources($line)['error'], $line);
        }
    }
}
