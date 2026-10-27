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

namespace KonradMichalik\Typo3Letterbox\Tests\Unit\Http;

use KonradMichalik\Typo3Letterbox\Domain\Model\{Attachment, Mail};
use KonradMichalik\Typo3Letterbox\Http\DownloadResponseFactory;
use KonradMichalik\Typo3Letterbox\Mbox\MboxFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\{ResponseFactory, StreamFactory};

/**
 * DownloadResponseFactoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class DownloadResponseFactoryTest extends TestCase
{
    #[Test]
    public function attachmentDispositionIsSafe(): void
    {
        $r = $this->factory()->attachment(new Attachment(0, "a\".html\r\nX-Evil: 1", '', 'text/html', 3, 'abc'));

        self::assertSame('application/octet-stream', $r->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $r->getHeaderLine('X-Content-Type-Options'));
        self::assertStringStartsWith('attachment; filename="a_.html__X-Evil__1"; filename*=UTF-8\'\'', $r->getHeaderLine('Content-Disposition'));
        self::assertStringEndsWith(rawurlencode("a\".html\r\nX-Evil: 1"), $r->getHeaderLine('Content-Disposition'));
        self::assertSame('abc', (string) $r->getBody());
    }

    #[Test]
    public function nonAsciiFilenameGetsFallbackAndEncodedName(): void
    {
        $r = $this->factory()->attachment(new Attachment(0, 'Übersicht.pdf', '', 'application/pdf', 1, 'x'));

        self::assertSame("attachment; filename=\"_bersicht.pdf\"; filename*=UTF-8''%C3%9Cbersicht.pdf", $r->getHeaderLine('Content-Disposition'));
    }

    #[Test]
    public function emptyFilenameFallsBackToAttachmentBin(): void
    {
        $r = $this->factory()->attachment(new Attachment(0, '', '', 'text/plain', 1, 'x'));

        self::assertStringStartsWith('attachment; filename="attachment.bin"', $r->getHeaderLine('Content-Disposition'));
    }

    #[Test]
    public function sourceIsCrlfEmlDownload(): void
    {
        $mail = new Mail('abc123', '<m@x>', 's', null, null, [], [], [], [], '', '', [], [], 0, "Subject: x\n\nbody\n", []);

        $r = $this->factory()->source($mail);

        self::assertSame("Subject: x\r\n\r\nbody\r\n", (string) $r->getBody());
        self::assertSame('application/octet-stream', $r->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $r->getHeaderLine('X-Content-Type-Options'));
        self::assertStringStartsWith('attachment; filename="abc123.eml"; filename*=UTF-8\'\'abc123.eml', $r->getHeaderLine('Content-Disposition'));
    }

    private function factory(): DownloadResponseFactory
    {
        return new DownloadResponseFactory(new ResponseFactory(), new StreamFactory(), new MboxFormat());
    }
}
