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

use KonradMichalik\Typo3Letterbox\Domain\Model\{Address, MailLink};
use KonradMichalik\Typo3Letterbox\Mbox\{MessageParser, MessageSpan};
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
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
        ], array_map(static fn (MailLink $link): string => $link->url, $mail->links));
    }

    #[Test]
    public function feloginResetLinkIsMarked(): void
    {
        $links = $this->linksOf('https://example.org/?tx_felogin_login%5Baction%5D=showChangePassword&tx_felogin_login%5Bcontroller%5D=PasswordRecovery&tx_felogin_login%5Bhash%5D=abc&cHash=1');

        self::assertTrue($links[0]->isPasswordReset);
        self::assertTrue($this->linksOf('https://example.org/?tx_felogin_login[hash]=abc')[0]->isPasswordReset);
    }

    #[Test]
    public function backendResetLinkIsMarked(): void
    {
        self::assertTrue($this->linksOf('https://example.org/typo3/login/password-reset/validate?t=1&i=2&e=3&c=4')[0]->isPasswordReset);
    }

    /**
     * BE.entryPoint moves the backend to another path or to a host of its own.
     */
    #[Test]
    public function backendResetLinkIsMarkedForEveryEntryPoint(): void
    {
        self::assertTrue($this->linksOf('https://example.org/admin/login/password-reset/validate?t=1')[0]->isPasswordReset);
        self::assertTrue($this->linksOf('https://backend.example.org/login/password-reset/validate?t=1')[0]->isPasswordReset);
    }

    #[Test]
    public function ordinaryLinkIsNotMarked(): void
    {
        $links = $this->linksOf('https://example.org/login/password-reset/info?x=tx_felogin_login');

        self::assertFalse($links[0]->isPasswordReset);
    }

    #[Test]
    public function attachmentsAreIndexedInOrder(): void
    {
        $mail = (new MessageParser())->parse($this->fixture('html-with-attachment.eml'));

        self::assertCount(2, $mail->attachments);
        self::assertSame([0, 1], [$mail->attachments[0]->index, $mail->attachments[1]->index]);
        self::assertSame(['report.txt', 'report.txt'], [$mail->attachments[0]->filename, $mail->attachments[1]->filename]);
        self::assertSame('first', trim($mail->attachments[0]->content()));
        self::assertSame('second content', trim($mail->attachments[1]->content()));
        self::assertSame('text/plain', $mail->attachments[1]->mimeType);
        self::assertSame(strlen($mail->attachments[1]->content()), $mail->attachments[1]->size);
    }

    #[Test]
    public function summarizeCarriesMessageIdAndToAndCcLikeParse(): void
    {
        $raw = $this->fixture('html-with-attachment.eml');
        $mail = (new MessageParser())->parse($raw);
        $entry = (new MessageParser())->summarize($raw, new MessageSpan(0, 10));

        self::assertSame($mail->messageId, $entry->messageId);
        self::assertEquals($mail->to, $entry->to);
        self::assertEquals($mail->cc, $entry->cc);
        self::assertSame('Rec One', $entry->to[0]->name);
    }

    #[Test]
    public function summarizeFlagsOnlyLinksOfTheBackendPasswordReset(): void
    {
        $parser = new MessageParser();
        $mail = static fn (string $headers, string $body): string => "To: a@example.org\nSubject: Reset\n".$headers."\n\n".$body."\n";
        $backend = 'https://stage.example.org/typo3/login/password-reset/validate?t=abc';

        self::assertTrue($parser->summarize($mail('Content-Type: text/plain', 'Open '.$backend), new MessageSpan(0, 10))->backendReset);
        self::assertTrue($parser->summarize($mail('Content-Type: text/html', '<a href="'.$backend.'">Reset</a>'), new MessageSpan(0, 10))->backendReset);
        self::assertFalse($parser->summarize($mail('Content-Type: text/plain', 'Open https://stage.example.org/login?tx_felogin_login%5Bhash%5D=abc'), new MessageSpan(0, 10))->backendReset);
        self::assertFalse($parser->summarize($mail('Content-Type: text/plain', 'No link at all'), new MessageSpan(0, 10))->backendReset);
        self::assertTrue($parser->summarize($mail('Content-Type: text/plain', 'Open https://backend.example.org/login/password-reset/validate?t=abc'), new MessageSpan(0, 10))->backendReset);
    }

    #[Test]
    public function summarizeFlagsTheBackendPasswordResetInALargeMail(): void
    {
        $raw = "To: a@example.org\nSubject: Reset\nContent-Type: text/plain\n\nOpen https://stage.example.org/typo3/login/password-reset/validate?t=abc\n".str_repeat("padding\n", 200000);

        self::assertTrue((new MessageParser())->summarize($raw, new MessageSpan(0, 10))->backendReset);
    }

    #[Test]
    public function linksAreReadFromTheBodiesLikeParse(): void
    {
        $raw = $this->fixture('html-with-attachment.eml');

        self::assertEquals((new MessageParser())->parse($raw)->links, (new MessageParser())->links($raw));
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

    #[Test]
    public function summarizeLabelsEachRecipientWithItsNameOrAddress(): void
    {
        $entry = (new MessageParser())->summarize($this->fixture('html-with-attachment.eml'), new MessageSpan(0, 10));

        self::assertSame(['Rec One', 'two@example.org', 'Carbon', 'hidden@example.org', 'envelope@example.org'], $entry->recipientLabels);
        self::assertCount(count($entry->recipients), $entry->recipientLabels);
    }

    #[Test]
    public function summarizeKeepsBccOutOfScopeRecipients(): void
    {
        $entry = (new MessageParser())->summarize($this->fixture('html-with-attachment.eml'), new MessageSpan(0, 10));

        self::assertSame(['one@example.org', 'two@example.org', 'cc@example.org', 'envelope@example.org'], $entry->recipientAddresses);
        self::assertContains('hidden@example.org', $entry->recipients);
    }

    /**
     * @return list<MailLink>
     */
    private function linksOf(string $url): array
    {
        return (new MessageParser())->parse("To: x@example.org\nSubject: l\n\nOpen {$url} now\n")->links;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$name);
    }
}
