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
use PHPUnit\Framework\Attributes\{DataProvider, Test};
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
        self::assertSame('Subject: x

From here
>From there
not From
', $f->decode($stored));
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
        self::assertTrue($f->isSeparator('From a@example.org Sun Nov 1 10:00:00 2026'));
        self::assertTrue($f->isSeparator("From a@example.org Sun Nov 1 10:00:00 2026\n"));
        self::assertTrue($f->isSeparator('From a@example.org Sun Nov 10 10:00:00 2026'));
        self::assertFalse($f->isSeparator('From a@example.org Sun Nov 100 10:00:00 2026'));
        self::assertFalse($f->isSeparator('From here on we talk'));
        self::assertFalse($f->isSeparator('>From a@example.org Mon Oct  6 23:02:03 2026'));
    }

    #[Test]
    public function decodeInvertsEncodeToCanonicalForm(): void
    {
        $f = new MboxFormat();

        self::assertSame("a\n", $f->decode($f->encode('a')));
        self::assertSame("a\n", $f->decode($f->encode("a\r\n\r\n")));
    }

    #[Test]
    public function decodeRemovesExactlyOneEscapeLevel(): void
    {
        self::assertSame(">From x\n", (new MboxFormat())->decode(">>From x\n"));
    }

    #[Test]
    public function fromAsFirstLineIsEscapedAndRestored(): void
    {
        $f = new MboxFormat();

        self::assertStringStartsWith(">From first\n", $f->encode("From first\nbody"));
        self::assertSame("From first\nbody\n", $f->decode($f->encode("From first\nbody")));
    }

    #[Test]
    #[DataProvider('senders')]
    public function separatorLineIsAlwaysAValidSeparator(string $sender): void
    {
        $f = new MboxFormat();
        $line = $f->separatorLine($sender, new DateTimeImmutable('2026-10-06 23:02:03', new DateTimeZone('UTC')));

        self::assertSame(1, substr_count($line, "\n"));
        self::assertTrue($f->isSeparator(rtrim($line, "\n")));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function senders(): array
    {
        return ['space' => ['john doe@x.org'], 'newline' => ["a@x.org\nFrom b@x.org Mon Oct  6 23:02:03 2026"]];
    }
}
