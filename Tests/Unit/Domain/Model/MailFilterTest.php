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

use function strlen;

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
    public function emptyQueryMatchesEverything(): void
    {
        self::assertTrue(MailFilter::create('   ', QuickFilter::All, [])->matches($this->entry()));
        self::assertSame('', MailFilter::create('   ', QuickFilter::All, [])->query());
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
    public function queryIsCappedAt200Characters(): void
    {
        $filter = MailFilter::create(str_repeat('ä', 250), QuickFilter::All, []);

        self::assertSame(200, mb_strlen($filter->query()));
        self::assertTrue($filter->matches($this->entry(str_repeat('ä', 200))));
        self::assertGreaterThan(200, strlen(str_repeat('ä', 250)));
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

    /**
     * @param list<string> $recipients
     */
    private function entry(
        string $subject = 'Hello',
        string $sender = 'a@example.com',
        string $fromName = '',
        array $recipients = ['b@example.com'],
        string $id = 'id',
        int $attachmentCount = 0,
    ): MessageIndexEntry {
        return new MessageIndexEntry(new MessageSpan(0, 10), $id, null, $sender, $fromName, $recipients, $subject, $attachmentCount);
    }
}
