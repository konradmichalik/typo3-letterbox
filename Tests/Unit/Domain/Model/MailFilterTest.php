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

namespace KonradMichalik\Typo3Letterbox\Tests\Unit\Domain\Model;

use KonradMichalik\Typo3Letterbox\Domain\Model\{MailFilter, QuickFilter};
use KonradMichalik\Typo3Letterbox\Mbox\{MessageIndexEntry, MessageSpan};
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MailFilterTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MailFilterTest extends TestCase
{
    #[Test]
    public function queryMatchesSubjectSenderNameAndRecipientsCaseInsensitive(): void
    {
        $entry = $this->entry('Password Reset', 'noreply@example.com', 'Ünïcode Sender', ['Editor@Example.org']);

        self::assertTrue(MailFilter::create('password', QuickFilter::All, [])->matches($entry));
        self::assertTrue(MailFilter::create('NOREPLY', QuickFilter::All, [])->matches($entry));
        self::assertTrue(MailFilter::create('ünïcode', QuickFilter::All, [])->matches($entry));
        self::assertTrue(MailFilter::create('editor@example', QuickFilter::All, [])->matches($entry));
        self::assertFalse(MailFilter::create('nothing', QuickFilter::All, [])->matches($entry));
    }

    #[Test]
    public function queryMatchesRecipientNames(): void
    {
        $entry = $this->entry('Hello', 'a@example.com', '', ['b@example.com'], 'id', 0, ['Bea Reader']);

        self::assertTrue(MailFilter::create('bea read', QuickFilter::All, [])->matches($entry));
        self::assertTrue(MailFilter::create('b@example', QuickFilter::All, [])->matches($entry));
    }

    #[Test]
    public function withoutBackendResetHidesFlaggedEntriesAndSurvivesEveryChange(): void
    {
        $reset = new MessageIndexEntry(new MessageSpan(0, 10), 'id', null, 'a@example.com', '', ['b@example.com'], ['b@example.com'], ['b@example.com'], 'Reset', 0, null, '', [], [], true);
        $filter = MailFilter::all()->withoutBackendReset();

        self::assertTrue(MailFilter::all()->matches($reset));
        self::assertFalse($filter->matches($reset));
        self::assertFalse($filter->withQuick(QuickFilter::All)->matches($reset));
        self::assertFalse($filter->withRecipient('b@example.com')->matches($reset));
        self::assertFalse($filter->withSubject('Reset')->matches($reset));
        self::assertTrue($filter->matches($this->entry()));
    }

    #[Test]
    public function emptyQueryMatchesEverything(): void
    {
        self::assertTrue(MailFilter::create('', QuickFilter::All, [])->matches($this->entry()));
        self::assertTrue(MailFilter::all()->matches($this->entry()));
    }

    #[Test]
    public function unreadExcludesReadIds(): void
    {
        $filter = MailFilter::create('', QuickFilter::Unread, ['read-id']);

        self::assertFalse($filter->matches($this->entry('Hello', 'a@example.com', '', ['b@example.com'], 'read-id')));
        self::assertTrue($filter->matches($this->entry('Hello', 'a@example.com', '', ['b@example.com'], 'other-id')));
    }

    #[Test]
    public function attachmentsRequiresAtLeastOne(): void
    {
        $filter = MailFilter::create('', QuickFilter::Attachments, []);

        self::assertFalse($filter->matches($this->entry('Hello', 'a@example.com', '', ['b@example.com'], 'id', 0)));
        self::assertTrue($filter->matches($this->entry('Hello', 'a@example.com', '', ['b@example.com'], 'id', 1)));
    }

    #[Test]
    public function unknownQuickFilterFallsBackToAll(): void
    {
        self::assertSame(QuickFilter::All, QuickFilter::fromRequest('bogus'));
        self::assertSame(QuickFilter::All, QuickFilter::fromRequest(null));
        self::assertSame(QuickFilter::Unread, QuickFilter::fromRequest('unread'));
        self::assertSame(QuickFilter::Attachments, QuickFilter::fromRequest('attachments'));
    }

    #[Test]
    public function queryWithMarkupIsTreatedAsPlainText(): void
    {
        $filter = MailFilter::create('"><svg onload=alert(1)>', QuickFilter::All, []);

        self::assertTrue($filter->matches($this->entry('x "><svg onload=alert(1)> y')));
        self::assertFalse($filter->matches($this->entry('svg')));
    }

    #[Test]
    public function withQuickKeepsQueryAndReadIds(): void
    {
        $filter = MailFilter::create('abc', QuickFilter::All, ['r'])->withQuick(QuickFilter::Unread);

        self::assertSame(QuickFilter::Unread, $filter->quick());
        self::assertSame('abc', $filter->query());
        self::assertFalse($filter->matches($this->entry('abc', 'a@example.com', '', ['b@example.com'], 'r')));
    }

    #[Test]
    public function recipientAndSubjectFiltersCombine(): void
    {
        $filter = MailFilter::all()->withRecipient('Anna@customer.example')->withSubject('RESET');

        self::assertTrue($filter->matches($this->addressed('Password reset', ['anna@customer.example'], ['anna@customer.example'])));
        self::assertFalse($filter->matches($this->addressed('Welcome', ['anna@customer.example'], ['anna@customer.example'])));
        self::assertFalse($filter->matches($this->addressed('Password reset', ['bob@customer.example'], ['bob@customer.example'])));
    }

    #[Test]
    public function recipientFilterIgnoresTheBccHeader(): void
    {
        $filter = MailFilter::all()->withRecipient('hidden@example.org');

        self::assertFalse($filter->matches($this->addressed('Hello', ['anna@example.org', 'hidden@example.org'], ['anna@example.org'])));
    }

    /**
     * @param list<string> $recipients         every recipient, Bcc included
     * @param list<string> $recipientAddresses To, Cc and envelope only
     */
    private function addressed(string $subject, array $recipients, array $recipientAddresses): MessageIndexEntry
    {
        return new MessageIndexEntry(new MessageSpan(0, 10), 'id', null, 'a@example.com', '', $recipients, $recipients, $recipientAddresses, $subject, 0);
    }

    /**
     * @param list<string> $recipients
     * @param list<string> $recipientLabels
     */
    private function entry(
        string $subject = 'Hello',
        string $sender = 'a@example.com',
        string $fromName = '',
        array $recipients = ['b@example.com'],
        string $id = 'id',
        int $attachmentCount = 0,
        array $recipientLabels = ['b@example.com'],
    ): MessageIndexEntry {
        return new MessageIndexEntry(new MessageSpan(0, 10), $id, null, $sender, $fromName, $recipients, $recipientLabels, $recipients, $subject, $attachmentCount);
    }
}
