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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\External\Tester;

use InvalidArgumentException;
use KonradMichalik\Typo3Letterbox\External\Tester\TesterLinkRepository;
use KonradMichalik\Typo3Letterbox\Mail\RecipientPattern;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * TesterLinkRepositoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TesterLinkRepositoryTest extends FunctionalTestCase
{
    private const TABLE = 'tx_typo3letterbox_tester_link';

    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    #[Test]
    public function issueStoresOnlyTheHash(): void
    {
        $issued = $this->subject()->issue('Review', [RecipientPattern::fromString('*@Customer.example')], false, true, false, 7, 3, 1000);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $issued->token);
        $rows = $this->connection()->select(['*'], self::TABLE)->fetchAllAssociative();
        self::assertCount(1, $rows);
        self::assertSame(hash('sha256', $issued->token), $rows[0]['token_hash']);
        self::assertStringNotContainsString($issued->token, json_encode($rows, \JSON_THROW_ON_ERROR));
        self::assertSame('*@customer.example', $rows[0]['scope']);
        self::assertSame(1000 + 7 * 86400, $issued->link->expires);
        self::assertTrue($issued->link->canDownload);
        self::assertFalse($issued->link->canDelete);
        self::assertSame(3, $issued->link->createdBy);
    }

    #[Test]
    public function issueRejectsEmptyScopeUnlessUnrestricted(): void
    {
        $this->subject()->issue('Open', [], true, false, false, 1, 1, 1000);

        $this->expectException(InvalidArgumentException::class);
        $this->subject()->issue('Closed', [], false, false, false, 1, 1, 1000);
    }

    #[Test]
    public function issueRejectsOtherDurations(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->subject()->issue('Review', [RecipientPattern::fromString('a@example.org')], false, false, false, 14, 1, 1000);
    }

    #[Test]
    public function findByTokenIgnoresRevokedAndExpiredViaIsUsable(): void
    {
        $scope = [RecipientPattern::fromString('a@example.org'), RecipientPattern::fromString('*@example.com')];
        $issued = $this->subject()->issue('Review', $scope, false, false, true, 1, 1, 1000);

        $found = $this->subject()->findByToken($issued->token);
        self::assertNotNull($found);
        self::assertSame($issued->link->uid, $found->uid);
        self::assertTrue($found->canDelete);
        self::assertCount(2, $found->scope);
        self::assertTrue($found->scope[1]->matches('x@example.com'));
        self::assertTrue($found->isUsable(1000 + 86399));
        self::assertFalse($found->isUsable(1000 + 86400));

        $this->subject()->revoke($found->uid, 1500);
        $revoked = $this->subject()->findByToken($issued->token);
        self::assertNotNull($revoked);
        self::assertFalse($revoked->isUsable(1600));
        self::assertSame(1500, $revoked->revoked);
    }

    #[Test]
    public function findByTokenRejectsMalformedAndUnknownTokens(): void
    {
        $this->subject()->issue('Review', [RecipientPattern::fromString('a@example.org')], false, false, false, 1, 1, 1000);

        self::assertNull($this->subject()->findByToken(''));
        self::assertNull($this->subject()->findByToken(str_repeat('A', 64)));
        self::assertNull($this->subject()->findByToken(str_repeat('a', 63)));
        self::assertNull($this->subject()->findByToken(str_repeat('a', 64)));
    }

    #[Test]
    public function aStoredUnparsableScopeMakesTheLinkUnusable(): void
    {
        $issued = $this->subject()->issue('Review', [RecipientPattern::fromString('a@example.org')], false, false, false, 1, 1, 1000);
        $this->connection()->update(self::TABLE, ['scope' => "a@example.org\n*"], ['uid' => $issued->link->uid]);

        $found = $this->subject()->findByToken($issued->token);

        self::assertNotNull($found);
        self::assertFalse($found->isUsable(1001));
    }

    #[Test]
    public function aStoredEmptyScopeWithoutTheFlagMakesTheLinkUnusable(): void
    {
        $issued = $this->subject()->issue('Review', [RecipientPattern::fromString('a@example.org')], false, false, false, 1, 1, 1000);
        $this->connection()->update(self::TABLE, ['scope' => ''], ['uid' => $issued->link->uid]);

        $found = $this->subject()->findByToken($issued->token);

        self::assertNotNull($found);
        self::assertFalse($found->isUsable(1001));
    }

    #[Test]
    public function findAllReturnsNewestFirst(): void
    {
        $first = $this->subject()->issue('First', [], true, false, false, 1, 1, 1000);
        $second = $this->subject()->issue('Second', [], true, false, false, 1, 1, 2000);

        self::assertSame([$second->link->uid, $first->link->uid], array_map(static fn ($link): int => $link->uid, $this->subject()->findAll()));
    }

    #[Test]
    public function touchThrottlesWrites(): void
    {
        $uid = $this->subject()->issue('Review', [], true, false, false, 1, 1, 100)->link->uid;

        $seen = [];
        foreach ([1000, 1060, 1061] as $now) {
            $this->subject()->touch($uid, $now);
            $seen[] = $this->lastUsed($uid);
        }

        self::assertSame([1000, 1000, 1061], $seen);
    }

    private function connection(): Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
    }

    private function subject(): TesterLinkRepository
    {
        return new TesterLinkRepository(GeneralUtility::makeInstance(ConnectionPool::class));
    }

    private function lastUsed(int $uid): int
    {
        $row = $this->connection()->select(['last_used'], self::TABLE, ['uid' => $uid])->fetchAssociative();
        self::assertIsArray($row);

        return (int) $row['last_used'];
    }
}
