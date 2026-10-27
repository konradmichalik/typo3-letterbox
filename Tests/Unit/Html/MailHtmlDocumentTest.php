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

namespace KonradMichalik\Typo3Letterbox\Tests\Unit\Html;

use KonradMichalik\Typo3Letterbox\Domain\Model\{Attachment, Mail};
use KonradMichalik\Typo3Letterbox\Html\MailHtmlDocument;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use PHPUnit\Framework\TestCase;

/**
 * MailHtmlDocumentTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MailHtmlDocumentTest extends TestCase
{
    private const SHELL = '<!DOCTYPE html><html><head><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'"><meta name="referrer" content="no-referrer"></head><body>';
    private const CSP = "default-src 'none'; img-src data:; style-src 'unsafe-inline'";

    #[Test]
    public function documentStartsWithCspAndDropsBaseAndRefresh(): void
    {
        $doc = (new MailHtmlDocument())->build($this->mailWithHtml('<base href="https://evil.test/"><meta http-equiv="refresh" content="0;url=https://evil.test"><p>Hi</p>'));

        self::assertStringContainsString(self::CSP, $doc);
        self::assertStringNotContainsString('<base', $doc);
        self::assertStringNotContainsStringIgnoringCase('http-equiv="refresh"', $doc);
        self::assertStringContainsString('<p>Hi</p>', $doc);
    }

    #[Test]
    public function cspMetaIsFirstInHeadAndMailCannotLoosenIt(): void
    {
        $doc = (new MailHtmlDocument())->build($this->mailWithHtml('<html><head><title>t</title><META HTTP-EQUIV="Content-Security-Policy" content="default-src *"><meta http-equiv="REFRESH" content="1"></head><body>x</body></html>'));

        self::assertSame(1, substr_count(strtolower($doc), 'content-security-policy'));
        self::assertStringNotContainsString('default-src *', $doc);
        self::assertStringNotContainsStringIgnoringCase('refresh', $doc);
        self::assertLessThan(strpos($doc, '<title>'), strpos($doc, 'Content-Security-Policy'));
        self::assertMatchesRegularExpression('/<head>\s*<meta http-equiv="Content-Security-Policy"/', $doc);
    }

    #[Test]
    public function cidIsInlinedOnlyForImages(): void
    {
        $mail = $this->mailWithHtml('<img src="cid:img"><a href="cid:page">x</a><img src="cid:svg">', [
            new Attachment(0, 'a.png', '<img>', 'image/PNG', 3, 'abc'),
            new Attachment(1, 'a.html', 'page', 'text/html', 3, 'def'),
            new Attachment(2, 'a.svg', 'svg', 'image/svg+xml', 3, 'ghi'),
        ]);

        $doc = (new MailHtmlDocument())->build($mail);

        self::assertStringContainsString('src="data:image/PNG;base64,'.base64_encode('abc').'"', $doc);
        self::assertStringContainsString('href="cid:page"', $doc);
        self::assertStringContainsString('data:image/svg+xml;base64,'.base64_encode('ghi'), $doc);
        self::assertStringNotContainsString('data:text/html', $doc);
    }

    #[Test]
    public function emptyHtmlYieldsEmptyString(): void
    {
        self::assertSame('', (new MailHtmlDocument())->build($this->mailWithHtml('')));
    }

    #[Test]
    public function quoteInHtmlCannotBreakSrcdocAfterFluidEscaping(): void
    {
        $escaped = htmlspecialchars((new MailHtmlDocument())->build($this->mailWithHtml('"><script>alert(1)</script>')), \ENT_QUOTES);

        self::assertStringNotContainsString('"><script', $escaped);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function headEscapeProvider(): iterable
    {
        yield 'div before head' => ['<div>x</div><head></head>'];
        yield 'img before html' => ['<img src="https://evil.test/early.gif"><html><head></head>'];
        yield 'head after body' => ['<body>x</body><head><title>t</title></head>'];
        yield 'head in svg' => ['<svg><head></head></svg><img src="https://evil.test/a.gif">'];
        yield 'head in template' => ['<template><head></head></template><img src="https://evil.test/a.gif">'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function differentialProvider(): iterable
    {
        yield 'processing instruction' => ['<?x a="b>" <meta http-equiv="refresh" content="0;url=https://evil.test/pi"> ?>'];
        yield 'cdata' => ['<![CDATA[ x> <meta http-equiv="refresh" content="0;url=https://evil.test/c"> ]]>'];
        yield 'style end tag space' => ['<style></style ><meta http-equiv="refresh" content="0;url=https://evil.test/s"></style>'];
        yield 'style end tag slash' => ['<style></style/><meta http-equiv="refresh" content="0;url=https://evil.test/s"></style>'];
        yield 'svg cdata' => ['<svg><![CDATA[ x> <base href="https://evil.test/"> ]]></svg>'];
    }

    #[Test]
    #[DataProvider('headEscapeProvider')]
    public function cspShellCannotBeEscapedViaHead(string $html): void
    {
        $doc = (new MailHtmlDocument())->build($this->mailWithHtml($html));

        self::assertStringStartsWith(self::SHELL, $doc);
        self::assertSame(1, substr_count(strtolower($doc), 'content-security-policy'));
    }

    #[Test]
    #[DataProvider('differentialProvider')]
    public function parserDifferentialPayloadsAreStripped(string $html): void
    {
        $doc = (new MailHtmlDocument())->build($this->mailWithHtml($html));

        self::assertStringStartsWith(self::SHELL, $doc);
        $outsideStyle = preg_replace('#<style>.*?</style>#s', '', $doc) ?? '';
        self::assertStringNotContainsStringIgnoringCase('http-equiv="refresh"', $outsideStyle);
        self::assertStringNotContainsStringIgnoringCase('<base', $outsideStyle);
        self::assertStringNotContainsString('evil.test', $outsideStyle);
        self::assertStringNotContainsString('</style >', $doc);
        self::assertStringNotContainsString('</style/>', $doc);
    }

    #[Test]
    public function styleEndTagsAreEscapedAndHeadStylesKept(): void
    {
        $doc = (new MailHtmlDocument())->build($this->mailWithHtml('<html><head><style>p{color:red}</style ></head><body><p>x</p></body></html>'));

        self::assertStringContainsString('<style>p{color:red}<\/style >', $doc);
    }

    #[Test]
    public function oversizedOrBrokenHtmlYieldsEmptyString(): void
    {
        self::assertSame('', (new MailHtmlDocument())->build($this->mailWithHtml('<p>'.str_repeat('a', 2097153).'</p>')));
    }

    #[Test]
    public function unreferencedAttachmentsAreNotEncodedAndMimeMustMatchExactly(): void
    {
        $mail = $this->mailWithHtml('<img src="cid:a">', [
            new Attachment(0, 'a.png', 'a', "image/png\n", 3, 'abc'),
            new Attachment(1, 'b.png', 'unused', 'image/png', 3, 'def'),
        ]);

        $doc = (new MailHtmlDocument())->build($mail);

        self::assertStringContainsString('src="cid:a"', $doc);
        self::assertStringNotContainsString('base64', $doc);
    }

    /**
     * @param list<Attachment> $attachments
     */
    private function mailWithHtml(string $html, array $attachments = []): Mail
    {
        return new Mail('id', '<m@x>', 's', null, null, [], [], [], [], '', $html, $attachments, [], 0, '', []);
    }
}
