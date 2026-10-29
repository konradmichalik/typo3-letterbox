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

use function strlen;

/**
 * MailHtmlDocumentTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MailHtmlDocumentTest extends TestCase
{
    private const SHELL = '<!DOCTYPE html><html><head><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'"><meta name="referrer" content="no-referrer"><style>';
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
    public function repeatedInlineImageIsEmbeddedOnceAndKeepsItsSize(): void
    {
        // A transparent PNG of 2 x 3 pixels.
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAYAAAC56t6BAAAAEUlEQVR4nGNgYGD4TyQeVAoAqY0t0zw9NLIAAAAASUVORK5CYII=', true);
        $mail = $this->mailWithHtml('<img src="cid:logo" class="a" alt="Logo"><p>x</p><img src="cid:logo" width="20"><img src="cid:logo" style="background:red">', [
            new Attachment(0, 'logo.png', '<logo>', 'image/png', 3, $png),
        ]);

        $doc = (new MailHtmlDocument())->build($mail);
        $placeholder = 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%222%22%20height%3D%223%22%2F%3E';

        self::assertSame(2, substr_count($doc, base64_encode($png)), 'Once in the stylesheet, once for the image with its own background.');
        self::assertSame(1, preg_match('#<style>img\.letterbox-cid-0\{background:url\("data:image/png;base64,[^"]+"\) no-repeat 0 0/100% 100% content-box!important\}</style>#', $doc));
        self::assertStringContainsString('<img src="'.$placeholder.'" class="a letterbox-cid-0" alt="Logo">', $doc);
        self::assertStringContainsString('<img src="'.$placeholder.'" width="20" class="letterbox-cid-0">', $doc);
        self::assertStringContainsString('<img src="data:image/png;base64,'.base64_encode($png).'" style="background:red">', $doc);
    }

    #[Test]
    public function repeatedInlineImageWithObjectFitKeepsItsDataUri(): void
    {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAYAAAC56t6BAAAAEUlEQVR4nGNgYGD4TyQeVAoAqY0t0zw9NLIAAAAASUVORK5CYII=', true);
        $mail = $this->mailWithHtml('<img src="cid:logo"><img src="cid:logo" style="width:200px;height:200px;object-fit:contain">', [
            new Attachment(0, 'logo.png', '<logo>', 'image/png', 3, $png),
        ]);

        $doc = (new MailHtmlDocument())->build($mail);

        self::assertStringContainsString('<img src="data:image/png;base64,'.base64_encode($png).'" style="width:200px;height:200px;object-fit:contain">', $doc);
    }

    #[Test]
    public function repeatedInlineImageStyledByAStylesheetWithObjectFitKeepsItsDataUri(): void
    {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAYAAAC56t6BAAAAEUlEQVR4nGNgYGD4TyQeVAoAqY0t0zw9NLIAAAAASUVORK5CYII=', true);
        $mail = $this->mailWithHtml('<style>.logo{width:200px;height:200px;object-fit:contain}</style><img src="cid:logo" class="logo"><img src="cid:logo" class="logo">', [
            new Attachment(0, 'logo.png', '<logo>', 'image/png', 3, $png),
        ]);

        $doc = (new MailHtmlDocument())->build($mail);

        self::assertSame(2, substr_count($doc, '<img src="data:image/png;base64,'.base64_encode($png).'" class="logo">'));
        self::assertStringNotContainsString('letterbox-cid-', $doc);
    }

    #[Test]
    public function repeatedImagesOfUnknownSizeKeepTheirDataUriOnEveryReference(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 20"><rect width="40" height="20"/></svg>';
        $mail = $this->mailWithHtml('<img src="cid:svg"><img src="cid:svg" alt="Second"><img src="cid:odd"><img src="cid:odd">', [
            new Attachment(0, 'a.svg', 'svg', 'image/svg+xml', 3, $svg),
            new Attachment(1, 'b.png', 'odd', 'image/png', 3, 'abc'),
        ]);
        $document = new MailHtmlDocument();

        $unresolved = $document->unresolvedContentIds($mail);
        $doc = $document->build($mail);

        self::assertSame(2, substr_count($doc, '<img src="data:image/svg+xml;base64,'.base64_encode($svg).'"'));
        self::assertSame(2, substr_count($doc, '<img src="data:image/png;base64,'.base64_encode('abc').'">'));
        self::assertStringNotContainsString('letterbox-cid-', $doc);
        self::assertSame([], $unresolved);
    }

    #[Test]
    public function repeatedCopiesOfAnUnknownSizeImageAreCappedAndReported(): void
    {
        // 2 MB of SVG text: two copies fit into the 5 MB cap, the third does not.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">'.str_repeat('<!-- padding -->', 98000).'</svg>';
        $mail = $this->mailWithHtml(str_repeat('<img src="cid:big">', 5).'<img src="cid:gone">', [
            new Attachment(0, 'big.svg', 'big', 'image/svg+xml', 3, $svg),
        ]);
        $document = new MailHtmlDocument();

        $unresolved = $document->unresolvedContentIds($mail);
        $doc = $document->build($mail);

        self::assertSame(2, substr_count($doc, base64_encode($svg)));
        self::assertSame(3, substr_count($doc, '<img src="cid:big">'));
        self::assertLessThan(6 * 1048576, strlen($doc));
        self::assertSame(['gone', 'big'], $unresolved);
    }

    #[Test]
    public function remoteImagesOnlyWidenImgSrc(): void
    {
        $mail = $this->mailWithHtml('<p>Hi</p>');

        $blocked = (new MailHtmlDocument())->build($mail);
        $allowed = (new MailHtmlDocument())->build($mail, true);

        self::assertStringContainsString('content="'.self::CSP.'"', $blocked);
        self::assertStringContainsString('content="default-src \'none\'; img-src data: https:; style-src \'unsafe-inline\'"', $allowed);
        self::assertSame(str_replace('img-src data:;', 'img-src data: https:;', $blocked), $allowed);
        self::assertStringContainsString('<meta name="referrer" content="no-referrer">', $allowed);
    }

    #[Test]
    public function shellPaintsWhiteCanvasAndFadesInOnlyWithoutReducedMotionPreference(): void
    {
        $doc = (new MailHtmlDocument())->build($this->mailWithHtml('<p>Hi</p>'));

        self::assertMatchesRegularExpression('/<head>.*<style>(?<css>.*)<\/style><\/head><body>/s', $doc);
        if (1 !== preg_match('/<head>.*<style>(?<css>.*)<\/style><\/head><body>/s', $doc, $match)) {
            self::fail('Shell style not found.');
        }
        $css = $match['css'];
        self::assertStringContainsString('html{background-color:Canvas;color-scheme:light}', $css);
        self::assertSame(1, substr_count($css, 'animation'));
        self::assertSame(1, preg_match('/@media \(prefers-reduced-motion:\s?no-preference\)\{.*animation:.*\}\}$/s', $css));
        self::assertStringNotContainsString('opacity:0', preg_replace('/@media.*$/s', '', $css) ?? '');
    }

    #[Test]
    public function unresolvedContentIdsListsOnlyReferencesWithoutMatchingPart(): void
    {
        $mail = $this->mailWithHtml('<img src="cid:logo"><img src="cid:gone"><img src="CID:Gone"><img src="cid:a%20b"><img src="cid:page"><img src="https://x.test/a.png">', [
            new Attachment(0, 'a.png', '<logo>', 'image/png', 3, 'abc'),
            new Attachment(1, 'a.html', '<page>', 'text/html', 3, 'def'),
        ]);

        self::assertSame(['gone', 'Gone', 'a b', 'page'], (new MailHtmlDocument())->unresolvedContentIds($mail));
    }

    #[Test]
    public function unresolvedContentIdsMatchesStrictlyAndIsEmptyWithoutHtml(): void
    {
        $mail = $this->mailWithHtml('<img src="cid:LOGO">', [new Attachment(0, 'a.png', '<logo>', 'image/png', 3, 'abc')]);

        self::assertSame(['LOGO'], (new MailHtmlDocument())->unresolvedContentIds($mail));
        self::assertSame([], (new MailHtmlDocument())->unresolvedContentIds($this->mailWithHtml('')));
        self::assertSame([], (new MailHtmlDocument())->unresolvedContentIds($this->mailWithHtml('<p>no images</p>')));
    }

    #[Test]
    public function unresolvedContentIdsAreCapped(): void
    {
        $html = '';
        for ($i = 0; $i < 30; ++$i) {
            $html .= '<img src="cid:missing'.$i.'">';
        }

        self::assertCount(20, (new MailHtmlDocument())->unresolvedContentIds($this->mailWithHtml($html)));
    }

    #[Test]
    public function linksOpenInNewTabWithoutOpenerOrReferrer(): void
    {
        $doc = (new MailHtmlDocument())->build($this->mailWithHtml('<a href="https://example.org/" target="_self" rel="opener">x</a><a name="anchor">y</a>'));

        self::assertStringContainsString('<a href="https://example.org/" target="_blank" rel="noopener noreferrer">x</a>', $doc);
        self::assertStringContainsString('<a name="anchor">y</a>', $doc);
    }

    #[Test]
    public function areasAndSvgLinksOpenInNewTabWithoutOpenerOrReferrer(): void
    {
        $doc = (new MailHtmlDocument())->build($this->mailWithHtml(
            '<map name="m"><area shape="rect" coords="0,0,1,1" href="https://example.org/area" target="_top" rel="opener"><area shape="rect" coords="1,1,2,2" alt="no link"></map>'
            .'<svg><a xlink:href="https://example.org/xlink" target="_parent"><text>x</text></a><a href="https://example.org/svg2" target="_top" rel="opener"><text>y</text></a><a><text>z</text></a></svg>',
        ));

        self::assertSame(1, preg_match('/<area\b[^>]*href="https:\/\/example\.org\/area"[^>]*>/', $doc, $area));
        self::assertStringContainsString('target="_blank"', $area[0] ?? '');
        self::assertStringContainsString('rel="noopener noreferrer"', $area[0] ?? '');
        self::assertSame(1, preg_match('/<area\b(?![^>]*href)[^>]*>/', $doc, $plainArea));
        self::assertStringNotContainsString('target', $plainArea[0] ?? 'target');
        self::assertSame(3, preg_match_all('/<a\b[^>]*>/', $doc, $anchors));
        self::assertStringContainsString('target="_blank"', $anchors[0][0], 'xlink:href');
        self::assertStringContainsString('rel="noopener noreferrer"', $anchors[0][0], 'xlink:href');
        self::assertStringContainsString('target="_blank"', $anchors[0][1], 'SVG href');
        self::assertStringContainsString('rel="noopener noreferrer"', $anchors[0][1], 'SVG href');
        self::assertStringNotContainsString('target', $anchors[0][2], 'an SVG a without link stays untouched');
        self::assertStringNotContainsString('_top', $doc);
        self::assertStringNotContainsString('_parent', $doc);
        self::assertStringNotContainsString('opener"', str_replace('noopener', '', $doc));
    }

    #[Test]
    public function deeplyNestedInputIsSanitisedQuickly(): void
    {
        $html = str_repeat('<div>', 5000).'deep<script>alert(1)</script>'.str_repeat('</div>', 5000);

        $start = microtime(true);
        $doc = (new MailHtmlDocument())->build($this->mailWithHtml($html));

        self::assertLessThan(5.0, microtime(true) - $start);
        self::assertStringContainsString('deep', $doc);
        self::assertStringNotContainsString('<script', $doc);
    }

    #[Test]
    public function inputWithTooManyElementsYieldsEmptyString(): void
    {
        self::assertSame('', (new MailHtmlDocument())->build($this->mailWithHtml(str_repeat('<i></i>', 50001))));
        self::assertNotSame('', (new MailHtmlDocument())->build($this->mailWithHtml(str_repeat('<i></i>', 1000))));
    }

    #[Test]
    public function emptyHtmlYieldsEmptyString(): void
    {
        self::assertSame('', (new MailHtmlDocument())->build($this->mailWithHtml('')));
    }

    #[Test]
    public function readersShareOneParseUntilBuildChangesTheDocument(): void
    {
        $document = new MailHtmlDocument();
        $mail = $this->mailWithHtml('<p>x</p><script>a()</script><img src="cid:gone">');

        $first = $document->parse($mail);
        self::assertTrue($document->canBuild($mail));
        self::assertSame(['gone'], $document->unresolvedContentIds($mail));
        self::assertSame($first, $document->parse($mail));

        self::assertStringNotContainsString('<script', $document->build($mail));
        $after = $document->parse($mail);

        self::assertNotSame($first, $after);
        self::assertSame(1, $after?->getElementsByTagName('script')->length);
        self::assertNotSame($document->parse($this->mailWithHtml('<p>x</p>')), $document->parse($this->mailWithHtml('<p>x</p>')));
    }

    #[Test]
    public function canBuildAgreesWithBuild(): void
    {
        $builder = new MailHtmlDocument();

        foreach (['', '   ', str_repeat('<i></i>', 50001), '<p>Hi</p>', 'Hello world'] as $html) {
            $mail = $this->mailWithHtml($html);
            self::assertSame('' !== $builder->build($mail), $builder->canBuild($mail), $html);
        }
        self::assertTrue($builder->canBuild($this->mailWithHtml('<p>Hi</p>')));
        self::assertFalse($builder->canBuild($this->mailWithHtml('')));
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
        yield 'svg style meta' => ['<svg><style><meta http-equiv="refresh" content="0;url=https://evil.test/"></style></svg>'];
        yield 'math style meta' => ['<math><style><meta http-equiv="refresh" content="0;url=https://evil.test/"></style></math>'];
        yield 'svg style base' => ['<svg><style><p><base href="https://evil.test/"><a href="x">'];
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
        $reparsed = (new \Masterminds\HTML5())->loadHTML($doc);
        $body = $reparsed->getElementsByTagName('body')->item(0);
        self::assertNotNull($body);
        self::assertCount(0, $body->getElementsByTagName('meta'));
        self::assertSame(0, $reparsed->getElementsByTagName('base')->length);
        self::assertStringNotContainsString('</style/>', $doc);
    }

    #[Test]
    public function styleEndTagsAreEscapedAndHeadStylesKept(): void
    {
        $doc = (new MailHtmlDocument())->build($this->mailWithHtml('<html><head><style>p{color:red}</style ></head><body><p>x</p></body></html>'));

        self::assertStringContainsString('<style>p{color:red}\3C /style >', $doc);
    }

    #[Test]
    public function leadingTextIsKept(): void
    {
        $builder = new MailHtmlDocument();

        self::assertStringContainsString('Hello world', $builder->build($this->mailWithHtml('Hello world')));
        self::assertStringContainsString('Dear user,<br>text', $builder->build($this->mailWithHtml('Dear user,<br>text')));
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
