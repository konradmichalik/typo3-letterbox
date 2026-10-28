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
        $issued = $this->subject()->issue('Review', [RecipientPattern::fromString('*@Customer.example')], 3, 1000);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $issued->token);
        $rows = $this->connection()->select(['*'], self::TABLE)->fetchAllAssociative();
        self::assertCount(1, $rows);
        self::assertSame(hash('sha256', $issued->token), $rows[0]['token_hash']);
        self::assertStringNotContainsString($issued->token, json_encode($rows, \JSON_THROW_ON_ERROR));
        self::assertSame('*@customer.example', $rows[0]['scope']);
        self::assertSame(3, $issued->link->createdBy);
    }

    #[Test]
    public function issuePurgesOldExpiredAndRevokedLinks(): void
    {
        $now = 100 * 86400;
        $day = 86400;
        $scope = [RecipientPattern::fromString('a@example.org')];
        $repository = $this->subject();
        $repository->issue('Expired 31 days ago', $scope, 1, $now - 38 * $day);
        $repository->issue('Expired 29 days ago', $scope, 1, $now - 36 * $day);
        $repository->revoke($repository->issue('Revoked 31 days ago', $scope, 1, $now - 32 * $day)->link->uid, $now - 31 * $day);
        $repository->revoke($repository->issue('Revoked 29 days ago', $scope, 1, $now - 30 * $day)->link->uid, $now - 29 * $day);
        $repository->issue('Active', $scope, 1, $now - $day);

        $repository->issue('New', $scope, 1, $now);

        $labels = array_column($this->connection()->select(['label'], self::TABLE)->fetchAllAssociative(), 'label');
        sort($labels);
        self::assertSame(['Active', 'Expired 29 days ago', 'New', 'Revoked 29 days ago'], $labels);
    }

    #[Test]
    public function issueRejectsAnEmptyScope(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->subject()->issue('Closed', [], 1, 1000);
    }

    #[Test]
    public function linksAreValidForSevenDays(): void
    {
        $issued = $this->subject()->issue('Review', [RecipientPattern::fromString('a@example.org')], 1, 1000);
        $found = $this->subject()->findByToken($issued->token);

        self::assertNotNull($found);
        self::assertSame(1000 + 7 * 86400, $issued->link->expires);
        self::assertSame($issued->link->expires, $found->expires);
        self::assertTrue($found->isUsable(1000 + 7 * 86400 - 1));
        self::assertFalse($found->isUsable(1000 + 7 * 86400));
    }

    #[Test]
    public function findByTokenIgnoresRevokedAndExpiredViaIsUsable(): void
    {
        $scope = [RecipientPattern::fromString('a@example.org'), RecipientPattern::fromString('*@example.com')];
        $issued = $this->subject()->issue('Review', $scope, 1, 1000);

        $found = $this->subject()->findByToken($issued->token);
        self::assertNotNull($found);
        self::assertSame($issued->link->uid, $found->uid);
        self::assertCount(2, $found->scope);
        self::assertTrue($found->scope[1]->matches('x@example.com'));
        self::assertTrue($found->isUsable(1001));

        $this->subject()->revoke($found->uid, 1500);
        $revoked = $this->subject()->findByToken($issued->token);
        self::assertNotNull($revoked);
        self::assertFalse($revoked->isUsable(1600));
        self::assertSame(1500, $revoked->revoked);
    }

    #[Test]
    public function findByTokenRejectsMalformedAndUnknownTokens(): void
    {
        $this->subject()->issue('Review', [RecipientPattern::fromString('a@example.org')], 1, 1000);

        self::assertNull($this->subject()->findByToken(''));
        self::assertNull($this->subject()->findByToken(str_repeat('A', 64)));
        self::assertNull($this->subject()->findByToken(str_repeat('a', 63)));
        self::assertNull($this->subject()->findByToken(str_repeat('a', 64)));
    }

    #[Test]
    public function aStoredUnparsableScopeMakesTheLinkUnusable(): void
    {
        $issued = $this->subject()->issue('Review', [RecipientPattern::fromString('a@example.org')], 1, 1000);
        $this->connection()->update(self::TABLE, ['scope' => "a@example.org\n*"], ['uid' => $issued->link->uid]);

        $found = $this->subject()->findByToken($issued->token);

        self::assertNotNull($found);
        self::assertFalse($found->isUsable(1001));
    }

    #[Test]
    public function aStoredEmptyScopeMakesTheLinkUnusable(): void
    {
        $issued = $this->subject()->issue('Review', [RecipientPattern::fromString('a@example.org')], 1, 1000);
        $this->connection()->update(self::TABLE, ['scope' => ''], ['uid' => $issued->link->uid]);

        $found = $this->subject()->findByToken($issued->token);

        self::assertNotNull($found);
        self::assertFalse($found->isUsable(1001));
    }

    #[Test]
    public function findAllReturnsNewestFirst(): void
    {
        $scope = [RecipientPattern::fromString('a@example.org')];
        $first = $this->subject()->issue('First', $scope, 1, 1000);
        $second = $this->subject()->issue('Second', $scope, 1, 2000);

        self::assertSame([$second->link->uid, $first->link->uid], array_map(static fn ($link): int => $link->uid, $this->subject()->findAll()));
    }

    private function connection(): Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
    }

    private function subject(): TesterLinkRepository
    {
        return new TesterLinkRepository(GeneralUtility::makeInstance(ConnectionPool::class));
    }
}
