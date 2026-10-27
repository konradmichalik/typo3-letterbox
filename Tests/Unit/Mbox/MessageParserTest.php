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

use KonradMichalik\Typo3Letterbox\Domain\Model\Address;
use KonradMichalik\Typo3Letterbox\Mbox\{MessageParser, MessageSpan};
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

/**
 * MessageParserTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MessageParserTest extends TestCase
{
    #[Test]
    public function idIsSha256PrefixOfRawBytes(): void
    {
        $raw = $this->fixture('html-with-attachment.eml');

        self::assertSame(substr(hash('sha256', $raw), 0, 32), (new MessageParser())->parse($raw)->id);
    }

    #[Test]
    public function groupedAddressesDoNotCrash(): void
    {
        $mail = (new MessageParser())->parse("To: Team: a@example.org, b@example.org;\nSubject: g\n\nx\n");

        self::assertSame(['a@example.org', 'b@example.org'], array_map(static fn (Address $a): string => $a->address, $mail->to));
    }

    #[Test]
    public function mailWithoutMessageIdFromAndSubjectIsParsed(): void
    {
        $mail = (new MessageParser())->parse("To: x@example.org\n\nbody\n");

        self::assertSame('', $mail->messageId);
        self::assertNull($mail->from);
        self::assertSame('', $mail->subject);
        self::assertNull($mail->date);
    }

    #[Test]
    public function parsesHeadersAddressesAndDecodedSubject(): void
    {
        $raw = $this->fixture('html-with-attachment.eml');
        $mail = (new MessageParser())->parse($raw);

        self::assertSame('Überprüfung', $mail->subject);
        self::assertSame('abc123@example.org', trim($mail->messageId, '<>'));
        self::assertSame('sender@example.org', $mail->from?->address);
        self::assertSame('Sender Name', $mail->from->name);
        self::assertSame(['one@example.org', 'two@example.org'], array_map(static fn (Address $a): string => $a->address, $mail->to));
        self::assertSame('Rec One', $mail->to[0]->name);
        self::assertSame(['cc@example.org'], array_map(static fn (Address $a): string => $a->address, $mail->cc));
        self::assertSame(['hidden@example.org'], array_map(static fn (Address $a): string => $a->address, $mail->bcc));
        self::assertSame(['reply@example.org'], array_map(static fn (Address $a): string => $a->address, $mail->replyTo));
        self::assertSame('2026-10-27T10:00:00+00:00', $mail->date?->format('c'));
        self::assertSame(strlen($raw), $mail->size);
        self::assertSame($raw, $mail->source);
        self::assertStringContainsString('Reset here', $mail->text);
        self::assertStringContainsString('<a href="https://example.org/other">', str_replace("'", '"', $mail->html));
        self::assertSame(['One@example.org, envelope@example.org'], $mail->headers['X-Letterbox-Envelope-To']);
    }

    #[Test]
    public function unparsableDateIsNull(): void
    {
        self::assertNull((new MessageParser())->parse("Date: not a date\nTo: x@example.org\n\nbody\n")->date);
    }

    #[Test]
    public function extractsLinksFromTextAndHtml(): void
    {
        $mail = (new MessageParser())->parse($this->fixture('html-with-attachment.eml'));

        self::assertSame([
            'https://example.org/reset?token=1&x=2',
            'http://example.org/docs',
            'https://example.org/other',
        ], $mail->links);
    }

    #[Test]
    public function attachmentsAreIndexedInOrder(): void
    {
        $mail = (new MessageParser())->parse($this->fixture('html-with-attachment.eml'));

        self::assertCount(2, $mail->attachments);
        self::assertSame([0, 1], [$mail->attachments[0]->index, $mail->attachments[1]->index]);
        self::assertSame(['report.txt', 'report.txt'], [$mail->attachments[0]->filename, $mail->attachments[1]->filename]);
        self::assertSame('first', trim($mail->attachments[0]->content));
        self::assertSame('second content', trim($mail->attachments[1]->content));
        self::assertSame('text/plain', $mail->attachments[1]->mimeType);
        self::assertSame(strlen($mail->attachments[1]->content), $mail->attachments[1]->size);
    }

    #[Test]
    public function summarizeCollectsDeduplicatedLowercaseRecipientsIncludingEnvelope(): void
    {
        $span = new MessageSpan(10, 20);
        $entry = (new MessageParser())->summarize($this->fixture('html-with-attachment.eml'), $span);

        self::assertSame($span, $entry->span);
        self::assertSame('sender@example.org', $entry->sender);
        self::assertSame('Überprüfung', $entry->subject);
        self::assertSame(['one@example.org', 'two@example.org', 'cc@example.org', 'hidden@example.org', 'envelope@example.org'], $entry->recipients);
        self::assertSame(32, strlen($entry->id));
        self::assertSame('2026-10-27T10:00:00+00:00', $entry->date?->format('c'));
    }

    #[Test]
    public function summarizeCarriesFromNameAndAttachmentCount(): void
    {
        $entry = (new MessageParser())->summarize($this->fixture('html-with-attachment.eml'), new MessageSpan(0, 10));

        self::assertSame('Sender Name', $entry->fromName);
        self::assertSame(2, $entry->attachmentCount);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$name);
    }
}
