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

use KonradMichalik\Typo3Letterbox\Domain\Model\{Address, Mail, MailFilter, QuickFilter};
use KonradMichalik\Typo3Letterbox\Mail\RecipientPattern;
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
    public function scopeMatchesAnyScopeRecipient(): void
    {
        $filter = MailFilter::all()->withScope([RecipientPattern::fromString('*@customer.example')]);

        self::assertTrue($filter->matches($this->scoped('Hello', ['x@example.org', 'Anna@Customer.example'], ['x@example.org', 'anna@customer.example'])));
        self::assertFalse($filter->matches($this->scoped('Hello', ['x@example.org'], ['x@example.org'])));
        self::assertTrue(MailFilter::all()->withScope([])->matches($this->entry()));
    }

    #[Test]
    public function scopeIgnoresDisplayNamesAndBccHeader(): void
    {
        $filter = MailFilter::all()->withScope([RecipientPattern::fromString('*@customer.example')]);
        $entry = $this->scoped('Hello', ['evil@example.org', 'anna@customer.example'], ['evil@example.org'], ['anna@customer.example', 'anna@customer.example']);

        self::assertFalse($filter->matches($entry));
    }

    #[Test]
    public function exclusionHidesMailWhenAnyRecipientIncludingBccMatches(): void
    {
        $filter = MailFilter::all()->withExclusions([RecipientPattern::fromString('vip@example.org')]);

        self::assertFalse($filter->matches($this->scoped('Hello', ['b@example.com', 'vip@example.org'], ['b@example.com'])));
        self::assertTrue($filter->matches($this->scoped('Hello', ['b@example.com'], ['b@example.com'])));
    }

    #[Test]
    public function recipientAndSubjectFiltersCombineWithScope(): void
    {
        $filter = MailFilter::all()
            ->withScope([RecipientPattern::fromString('*@customer.example')])
            ->withRecipient('Anna@customer.example')
            ->withSubject('RESET');
        $match = $this->scoped('Password reset', ['anna@customer.example'], ['anna@customer.example']);

        self::assertTrue($filter->matches($match));
        self::assertFalse($filter->matches($this->scoped('Welcome', ['anna@customer.example'], ['anna@customer.example'])));
        self::assertFalse($filter->matches($this->scoped('Password reset', ['bob@customer.example'], ['bob@customer.example'])));
        self::assertFalse($filter->matches($this->scoped('Password reset', ['anna@other.example'], ['anna@other.example'])));
    }

    #[Test]
    public function visibleRecipientsMasksOthers(): void
    {
        $mail = new Mail(
            'id', 'mid', 'Hello', null, null,
            [new Address('anna@customer.example', 'Anna'), new Address('evil@example.org', 'anna@customer.example')],
            [new Address('Bob@Customer.example')],
            [], [], '', '', [], [], 0, '', [],
        );

        $visible = MailFilter::all()->withScope([RecipientPattern::fromString('*@customer.example')])->visibleRecipients($mail);

        self::assertSame(['anna@customer.example', 'Bob@Customer.example'], array_map(static fn (Address $a): string => $a->address, $visible));
        self::assertCount(3, MailFilter::all()->visibleRecipients($mail));
    }

    /**
     * @param list<string>      $recipients
     * @param list<string>      $scopeRecipients
     * @param list<string>|null $labels          defaults to the recipients
     */
    private function scoped(string $subject, array $recipients, array $scopeRecipients, ?array $labels = null): MessageIndexEntry
    {
        return new MessageIndexEntry(new MessageSpan(0, 10), 'id', null, 'a@example.com', '', $recipients, $labels ?? $recipients, $scopeRecipients, $subject, 0);
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
