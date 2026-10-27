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

use DateTimeImmutable;
use DateTimeZone;
use KonradMichalik\Typo3Letterbox\Mbox\MboxFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;


/**
 * MboxFormatTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */

final class MboxFormatTest extends TestCase
{
    #[Test]
    public function separatorUsesUtcAndPaddedDay(): void
    {
        $date = new DateTimeImmutable('2026-10-07 01:02:03', new DateTimeZone('Europe/Berlin'));

        self::assertSame("From a@example.org Tue Oct  6 23:02:03 2026\n", (new MboxFormat())->separatorLine('a@example.org', $date));
    }

    #[Test]
    public function emptySenderBecomesMailerDaemon(): void
    {
        $date = new DateTimeImmutable('2026-10-27 10:00:00', new DateTimeZone('UTC'));

        self::assertSame("From MAILER-DAEMON Tue Oct 27 10:00:00 2026\n", (new MboxFormat())->separatorLine('', $date));
    }

    #[Test]
    public function encodeEscapesAndDecodeRestoresFromLines(): void
    {
        $raw = "Subject: x\r\n\r\nFrom here\r\n>From there\r\nnot From\r\n";
        $f = new MboxFormat();
        $stored = $f->encode($raw);

        self::assertStringContainsString("\n>From here\n>>From there\nnot From\n", $stored);
        self::assertStringNotContainsString("\r", $stored);
        self::assertSame("Subject: x\n\nFrom here\n>From there\nnot From\n", rtrim($f->decode($stored), "\n")."\n");
    }

    #[Test]
    public function encodeEndsWithExactlyOneEmptyLine(): void
    {
        $f = new MboxFormat();

        self::assertSame("a\n\n", $f->encode('a'));
        self::assertSame("a\n\n", $f->encode("a\r\n"));
        self::assertSame("a\n\n", $f->encode("a\n\n\n"));
    }

    #[Test]
    public function toCrlfConvertsLineFeeds(): void
    {
        self::assertSame("a\r\nb\r\n", (new MboxFormat())->toCrlf("a\nb\n"));
    }

    #[Test]
    public function onlyStrictSeparatorsCount(): void
    {
        $f = new MboxFormat();

        self::assertTrue($f->isSeparator('From a@example.org Mon Oct  6 23:02:03 2026'));
        self::assertTrue($f->isSeparator("From a@example.org Mon Oct 16 23:02:03 2026\n"));
        self::assertFalse($f->isSeparator('From here on we talk'));
        self::assertFalse($f->isSeparator('>From a@example.org Mon Oct  6 23:02:03 2026'));
    }
}
