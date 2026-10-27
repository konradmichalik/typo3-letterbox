<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_letterbox" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3Letterbox\Tests\Unit\Mbox;

use KonradMichalik\Typo3Letterbox\Mbox\TextSegments;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TextSegmentsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TextSegmentsTest extends TestCase
{
    #[Test]
    public function urlInTheMiddleSplitsTheText(): void
    {
        self::assertSame([
            ['text' => 'Open ', 'url' => null],
            ['text' => 'https://example.org/a?b=1', 'url' => 'https://example.org/a?b=1'],
            ['text' => ' now', 'url' => null],
        ], TextSegments::split('Open https://example.org/a?b=1 now'));
    }

    #[Test]
    public function trailingPunctuationStaysOutsideTheUrl(): void
    {
        self::assertSame([
            ['text' => 'See ', 'url' => null],
            ['text' => 'http://example.org/x', 'url' => 'http://example.org/x'],
            ['text' => '.', 'url' => null],
        ], TextSegments::split('See http://example.org/x.'));
    }

    #[Test]
    public function twoUrlsAreBothLinked(): void
    {
        $urls = array_column(TextSegments::split("https://a.example/\nhttps://b.example/x"), 'url');

        self::assertSame(['https://a.example/', null, 'https://b.example/x'], $urls);
    }

    #[Test]
    public function otherSchemesAndHtmlStayPlainText(): void
    {
        self::assertSame(
            [['text' => 'javascript:alert(1) <b>x</b>', 'url' => null]],
            TextSegments::split('javascript:alert(1) <b>x</b>'),
        );
    }

    #[Test]
    public function textWithoutUrlIsOneSegmentAndEmptyTextNone(): void
    {
        self::assertSame([['text' => 'plain', 'url' => null]], TextSegments::split('plain'));
        self::assertSame([], TextSegments::split(''));
    }
}
