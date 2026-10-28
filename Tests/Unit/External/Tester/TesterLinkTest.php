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

namespace KonradMichalik\Typo3Letterbox\Tests\Unit\External\Tester;

use KonradMichalik\Typo3Letterbox\Domain\Model\{MailFilter, QuickFilter};
use KonradMichalik\Typo3Letterbox\External\Tester\TesterLink;
use KonradMichalik\Typo3Letterbox\Mail\RecipientPattern;
use KonradMichalik\Typo3Letterbox\Mbox\{MessageIndexEntry, MessageSpan};
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TesterLinkTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TesterLinkTest extends TestCase
{
    #[Test]
    public function filterAddsLinkScopeToBaseFilter(): void
    {
        $link = $this->link([RecipientPattern::fromString('*@customer.example')]);
        $filter = $link->filter(MailFilter::all()->withExclusions([RecipientPattern::fromString('vip@customer.example')]));

        self::assertTrue($filter->matches($this->entry(['anna@customer.example'])));
        self::assertFalse($filter->matches($this->entry(['anna@other.example'])));
        self::assertFalse($filter->matches($this->entry(['anna@customer.example', 'vip@customer.example'])));
    }

    #[Test]
    public function filterNeverWidensTheBaseScope(): void
    {
        $link = $this->link([RecipientPattern::fromString('*@customer.example')]);
        $filter = $link->filter(MailFilter::all()->withScope([RecipientPattern::fromString('anna@customer.example')]));

        self::assertTrue($filter->matches($this->entry(['anna@customer.example'])));
        self::assertFalse($filter->matches($this->entry(['bob@customer.example'])));
        self::assertFalse($filter->matches($this->entry(['anna@other.example'])));
    }

    #[Test]
    public function unrestrictedLinkKeepsTheBaseFilterAsIs(): void
    {
        $link = $this->link([], true);
        $base = MailFilter::all()->withScope([RecipientPattern::fromString('anna@customer.example')]);

        self::assertTrue($link->filter($base)->matches($this->entry(['anna@customer.example'])));
        self::assertFalse($link->filter($base)->matches($this->entry(['bob@customer.example'])));
        self::assertTrue($link->filter(MailFilter::all())->isUnrestricted());
        self::assertSame(QuickFilter::All, $link->filter(MailFilter::all())->quick());
    }

    #[Test]
    public function isUsableChecksRevocationExpiryAndScope(): void
    {
        $pattern = [RecipientPattern::fromString('a@example.org')];

        self::assertTrue($this->link($pattern)->isUsable(1999));
        self::assertFalse($this->link($pattern)->isUsable(2000));
        self::assertFalse($this->link($pattern, false, 1500)->isUsable(1000));
        self::assertFalse($this->link($pattern, false, 0, false)->isUsable(1000));
        self::assertFalse($this->link([])->isUsable(1000));
        self::assertFalse($this->link($pattern, true)->isUsable(1000));
        self::assertTrue($this->link([], true)->isUsable(1000));
    }

    #[Test]
    public function aCorruptScopeLinkMatchesNothing(): void
    {
        $link = $this->link([], false);

        self::assertFalse($link->filter(MailFilter::all())->matches($this->entry(['anna@customer.example'])));
    }

    /**
     * @param list<RecipientPattern> $scope
     */
    private function link(array $scope, bool $unrestricted = false, int $revoked = 0, bool $scopeValid = true): TesterLink
    {
        return new TesterLink(1, 'Label', $scope, $unrestricted, false, false, 2000, 1000, 1, 0, $revoked, $scopeValid);
    }

    /**
     * @param list<string> $recipients
     */
    private function entry(array $recipients): MessageIndexEntry
    {
        return new MessageIndexEntry(new MessageSpan(0, 10), 'id', null, 'a@example.com', '', $recipients, $recipients, $recipients, 'Hello', 0);
    }
}
