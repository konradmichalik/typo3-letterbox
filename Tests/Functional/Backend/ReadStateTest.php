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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\Backend;

use KonradMichalik\Typo3Letterbox\Backend\ReadState;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * ReadStateTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ReadStateTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__.'/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function markReadPersistsForTheBackendUser(): void
    {
        $state = new ReadState();
        self::assertSame([], $state->readIds());

        $state->markRead('a');

        self::assertSame(['a'], $state->readIds());
        $uc = unserialize((string) $this->storedUc(), ['allowed_classes' => false]);
        self::assertSame(['a'], $uc['letterbox']['read']);
    }

    #[Test]
    public function readListIsCappedAt2000(): void
    {
        $state = new ReadState();
        for ($i = 1; $i <= 2001; ++$i) {
            $state->markRead('id'.$i);
        }

        $ids = $state->readIds();
        self::assertCount(2000, $ids);
        self::assertNotContains('id1', $ids);
        self::assertSame('id2', $ids[0]);
        self::assertSame('id2001', $ids[1999]);
    }

    #[Test]
    public function markingTwiceKeepsOneEntry(): void
    {
        $state = new ReadState();
        $state->markRead('a');
        $state->markRead('b');
        $state->markRead('a');

        self::assertSame(['a', 'b'], $state->readIds());
    }

    #[Test]
    public function markingAnAlreadyReadMailDoesNotWrite(): void
    {
        $state = new ReadState();
        $state->markRead('a');
        // Only held in memory: a write of uc would persist it.
        $backendUser = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $backendUser);
        $backendUser->uc['letterbox']['unsaved'] = '1';

        $state->markRead('a');

        $uc = unserialize((string) $this->storedUc(), ['allowed_classes' => false]);
        self::assertSame(['letterbox' => ['read' => ['a']]], array_intersect_key($uc, ['letterbox' => true]));
    }

    #[Test]
    public function markAllReadAddsTheIdsWithOneWriteAndKeepsTheNewest(): void
    {
        $state = new ReadState();
        $state->markRead('old');

        // Newest first, as the repository lists mails.
        $state->markAllRead(['c', 'b', 'old', 'a']);

        self::assertSame(['old', 'a', 'b', 'c'], $state->readIds());
        $uc = unserialize((string) $this->storedUc(), ['allowed_classes' => false]);
        self::assertSame(['old', 'a', 'b', 'c'], $uc['letterbox']['read']);
    }

    #[Test]
    public function markAllReadRespectsTheCap(): void
    {
        $state = new ReadState();
        $ids = [];
        for ($i = 2001; $i >= 1; --$i) {
            $ids[] = 'id'.$i;
        }

        $state->markAllRead($ids);

        $read = $state->readIds();
        self::assertCount(2000, $read);
        self::assertNotContains('id1', $read);
        self::assertSame('id2001', $read[1999]);
    }

    private function storedUc(): mixed
    {
        return $this->get(ConnectionPool::class)
            ->getConnectionForTable('be_users')
            ->select(['uc'], 'be_users', ['uid' => 1])
            ->fetchOne();
    }
}
