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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MailHtmlDocumentTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MailHtmlDocumentTest extends TestCase
{
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
     * @param list<Attachment> $attachments
     */
    private function mailWithHtml(string $html, array $attachments = []): Mail
    {
        return new Mail('id', '<m@x>', 's', null, null, [], [], [], [], '', $html, $attachments, [], 0, '', []);
    }
}
