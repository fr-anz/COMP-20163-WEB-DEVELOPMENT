<?php

declare(strict_types=1);

namespace Habi\Tests\Views;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * Shared plain-text rendering for stories, journal bodies and sources
 * (views/macros/content.twig): escaped, one paragraph per blank-line block.
 */
final class ContentMacrosTest extends TestCase
{
    public function testParagraphsSplitOnBlankLinesAndEscapeHtml(): void
    {
        $html = $this->render(
            '{{ content.paragraphs(text, "mt-3") }}',
            ['text' => "First line\nsame paragraph.\r\n\r\n<script>alert(1)</script>\n\n\n\n  Third.  \n\n"]
        );

        self::assertSame(
            '<p class="mt-3">First line' . "\n" . 'same paragraph.</p>'
            . '<p class="mt-3">&lt;script&gt;alert(1)&lt;/script&gt;</p>'
            . '<p class="mt-3">Third.</p>',
            $this->squash($html)
        );
        self::assertSame('', trim($this->render('{{ content.paragraphs(text) }}', ['text' => " \n\n "])));
    }

    public function testSourcesBecomeAnOrderedListWithSafeLinks(): void
    {
        $html = $this->squash($this->render('{{ content.sources(text) }}', ['text' => implode("\n", [
            'Reyes, A. Weaving in Basey. Museum Press, 2019. https://example.org/basey?a=1&b=2',
            '',
            'National Museum. Mats of Samar. NM, 2020.',
            'Bad Org. Trick. X, 2021. javascript:alert(1)',
            '<b>Html</b> Org. Title. P, 2022.',
        ])]));

        self::assertStringStartsWith('<ol', $html);
        self::assertSame(4, substr_count($html, '<li'));
        self::assertStringContainsString(
            'Reyes, A. Weaving in Basey. Museum Press, 2019. <a class="link"'
            . ' href="https://example.org/basey?a=1&amp;b=2" rel="noopener">https://example.org/basey?a=1&amp;b=2</a>',
            $html
        );
        self::assertStringContainsString('<li>National Museum. Mats of Samar. NM, 2020.</li>', $html);
        self::assertStringNotContainsString('href="javascript', $html);
        self::assertStringContainsString('&lt;b&gt;Html&lt;/b&gt;', $html);
        self::assertSame('', trim($this->render('{{ content.sources(text) }}', ['text' => "\n \n"])));
    }

    /** @param array<string, mixed> $context */
    private function render(string $template, array $context): string
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['test.twig' => '{% import "macros/content.twig" as content %}' . $template]),
            new FilesystemLoader(dirname(__DIR__, 2) . '/views'),
        ]), ['autoescape' => 'html', 'strict_variables' => true]);

        return $twig->render('test.twig', $context);
    }

    /** Drops whitespace between tags so assertions ignore template indentation. */
    private function squash(string $html): string
    {
        return trim((string) preg_replace('/>\s+</', '><', $html));
    }
}
