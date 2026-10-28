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

use KonradMichalik\Typo3Letterbox\Mbox\{AttachmentCounter, MboxFormat, MessageParser, MessageSpan};
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * AttachmentCounterTest.
 *
 * The index count has to equal mail-mime-parser's getAttachmentCount(), which the detail view uses.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AttachmentCounterTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function symfonyMails(): iterable
    {
        $email = static fn (): Email => (new Email())->from('a@example.org')->to('b@example.org')->subject('s');
        yield 'text' => [$email()->text('Hello')->toString()];
        yield 'html only' => [$email()->html('<p>Hello</p>')->toString()];
        yield 'alternative' => [$email()->text('Hello')->html('<p>Hello</p>')->toString()];
        yield 'inline image' => [$email()->text('Hello')->html('<img src="cid:logo">')->embed('PNG', 'logo', 'image/png')->toString()];
        yield 'attachment' => [$email()->text('Hello')->attach('%PDF', 'a.pdf', 'application/pdf')->toString()];
        yield 'everything' => [$email()->text('Hello')->html('<img src="cid:logo">')->embed('PNG', 'logo', 'image/png')
            ->attach('%PDF', 'a.pdf', 'application/pdf')->attach('<p>x</p>', 'a.html', 'text/html')->attach('x', 'a.txt', 'text/plain')->toString()];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusualMails(): iterable
    {
        $mixed = static fn (string $parts, string $type = 'multipart/mixed; boundary=b'): string => "MIME-Version: 1.0\nContent-Type: {$type}\n\n{$parts}";
        yield 'no MIME, uuencoded' => ["Subject: x\n\nbegin 644 a.txt\n#86)C\n`\nend\n"];
        yield 'MIME-Version only' => ["MIME-Version: 1.0\nSubject: x\n\nbody\n"];
        yield 'single PDF' => ["MIME-Version: 1.0\nContent-Type: application/pdf\n\n%PDF\n"];
        yield 'text as attachment' => ["MIME-Version: 1.0\nContent-Disposition: attachment\n\nbody\n"];
        yield 'comment in type' => [$mixed("--b\nContent-Type: image/png (x)\n\ny\n--b--\n", 'multipart/mixed (c); boundary="b"')];
        yield 'unknown disposition' => [$mixed("--b\nContent-Type: text/plain\nContent-Disposition: foo\n\nx\n--b\nContent-Type: text/html\nContent-Disposition: attachment; filename=a.html\n\ny\n--b--\n")];
        yield 'attached multipart' => [$mixed("--b\nContent-Type: multipart/mixed; boundary=c\nContent-Disposition: attachment\n\n--c\nContent-Type: image/png\n\nx\n--c--\n--b--\n")];
        yield 'nested inline multipart' => [$mixed("--b\nContent-Type: multipart/related; boundary=\"c c\"\n\n--c c\nContent-Type: text/html\n\nx\n--c c\nContent-Type: image/png\n\ny\n--c c--\n--b\nContent-Type: application/pdf\n\nz\n--b--\n")];
        yield 'signed' => [$mixed("--b\nContent-Type: text/plain\n\nx\n--b\nContent-Type: application/pgp-signature\n\nsig\n--b--\n", 'multipart/signed; boundary=b; protocol="application/pgp-signature"')];
        yield 'signed inside mixed' => [$mixed("--b\nContent-Type: multipart/signed; boundary=c\n\n--c\nContent-Type: text/plain\n\nx\n--c\nContent-Type: application/pgp-signature\n\nsig\n--c--\n--b--\n")];
        yield 'attached message' => [$mixed("--b\nContent-Type: message/rfc822\n\nSubject: inner\nMIME-Version: 1.0\nContent-Type: multipart/mixed; boundary=c\n\n--c\nContent-Type: image/png\n\ny\n--c--\n--b--\n")];
        yield 'missing end delimiter' => [$mixed("--b\nContent-Type: image/png\n\nx\n--b\nContent-Type: image/gif\n\ny\n")];
        yield 'inner part cut by the outer delimiter' => [$mixed("--b\nContent-Type: multipart/mixed; boundary=c\n\n--c\nContent-Type: image/png\n\nx\n--b\nContent-Type: image/gif\n\ny\n--b--\n")];
        yield 'same boundary nested' => [$mixed("--b\nContent-Type: multipart/mixed; boundary=b\n\n--b\nContent-Type: image/png\n\nx\n--b--\n--b--\n")];
        yield 'hidden content after the end' => [$mixed("--b\nContent-Type: image/png\n\nx\n--b--\n--x\nContent-Type: image/gif\n\nhidden\n")];
        yield 'delimiter with trailing space' => [$mixed("--b \nContent-Type: image/png\n\nx\n--b\nContent-Type: image/gif\n\ny\n--b--\n")];
        yield 'boundary as prefix' => [$mixed("--b\nContent-Type: image/png\n\nx\n--bb\nnot a part\n--b--\n")];
        yield 'part without headers' => [$mixed("--b\n\nplain text\n--b\nContent-Type: image/png\n\nx\n--b--\n")];
        yield 'part without blank line' => [$mixed("--b\nContent-Type: image/png\n--b--\n")];
        yield 'folded headers' => [$mixed("--b\nContent-Type: image/png;\n name=\"a.png\"\nContent-Disposition:\n attachment\n\nx\n--b--\n")];
        yield 'uppercase' => [$mixed("--b\nCONTENT-TYPE: TEXT/HTML\ncontent-disposition: INLINE\n\nx\n--b\nContent-Type: Image/PNG\n\ny\n--b--\n", 'Multipart/Mixed; BOUNDARY=b')];
        yield 'RFC 2231 boundary' => ["MIME-Version: 1.0\nContent-Type: multipart/mixed; boundary*0=b\n\n--b\nContent-Type: image/png\n\nx\n--b--\n"];
        yield 'encoded word' => [$mixed("--b\nContent-Type: =?utf-8?q?image/png?=\n\nx\n--b--\n")];
        yield 'CRLF' => [str_replace("\n", "\r\n", $mixed("--b\nContent-Type: image/png\n\nx\n--b--\n"))];
        yield 'multipart without boundary' => ["MIME-Version: 1.0\nContent-Type: multipart/mixed\nContent-Disposition: attachment\n\n--b\nContent-Type: image/png\n\nx\n"];
        yield 'empty multipart' => [$mixed("preamble\n--b--\n")];
        yield 'no body' => ["MIME-Version: 1.0\nContent-Type: application/pdf"];
    }

    #[Test]
    #[DataProvider('symfonyMails')]
    public function plainMailsAreCountedFromTheirPartHeaders(string $raw): void
    {
        $raw = (new MboxFormat())->decode((new MboxFormat())->encode($raw));

        self::assertSame(self::expected($raw), AttachmentCounter::count($raw));
    }

    #[Test]
    #[DataProvider('symfonyMails')]
    #[DataProvider('unusualMails')]
    public function summaryCountEqualsMailMimeParser(string $raw): void
    {
        self::assertSame(self::expected($raw), (new MessageParser())->summarize($raw, new MessageSpan(0, 1))->attachmentCount);
    }

    private static function expected(string $raw): int
    {
        return (new MailMimeParser())->parse($raw, true)->getAttachmentCount();
    }
}
