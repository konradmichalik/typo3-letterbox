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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\Domain\Repository;

use DateTimeImmutable;
use DateTimeZone;
use KonradMichalik\Typo3Letterbox\Domain\Model\{MailFilter, QuickFilter};
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\Mbox\{MailIndex, Mailbox, MailboxLock, MailboxWriter, MboxFormat, MessageIndexEntry, MessageParser};
use KonradMichalik\Typo3Letterbox\Tests\Unit\Mbox\Fixtures\ExtMboxFixture;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\{LockFactory, LockingStrategyInterface};
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function dirname;

/**
 * MailRepositoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MailRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    private string $path = '';

    private MailboxWriter $writer;

    private MailRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = Environment::getVarPath().'/letterbox-test/mails.mbox';
        GeneralUtility::mkdir_deep(dirname($this->path));
        if (is_file($this->path)) {
            unlink($this->path);
        }

        $this->get(CacheManager::class)->getCache('typo3_letterbox_index')->flush();
        $this->writer = new MailboxWriter($this->path, new MboxFormat(), new MailboxLock($this->get(LockFactory::class)));
        $this->repository = $this->repositoryWith($this->writer);
    }

    #[Test]
    public function missingFileCountsZero(): void
    {
        self::assertSame(0, $this->repository->count(MailFilter::all()));
        self::assertSame([], $this->repository->find(MailFilter::all(), 0, 10));
        self::assertNull($this->repository->findById('0123456789abcdef0123456789abcdef'));
        self::assertSame(0, $this->repository->sizeInBytes());
        self::assertSame($this->path, $this->repository->path());
    }

    #[Test]
    public function findReturnsNewestFirstWithPaging(): void
    {
        foreach (['one', 'two', 'three'] as $subject) {
            $this->append($subject);
        }

        self::assertSame(3, $this->repository->count(MailFilter::all()));
        self::assertSame(['three', 'two', 'one'], $this->subjects($this->repository->find(MailFilter::all(), 0, 10)));
        self::assertSame(['two'], $this->subjects($this->repository->find(MailFilter::all(), 1, 1)));
    }

    #[Test]
    public function findEntriesReturnsNewestFirstWithoutReadingTheMails(): void
    {
        $padding = str_repeat('x', 4000);
        for ($i = 1; $i <= 30; ++$i) {
            $this->writer->append('s@example.org', new DateTimeImmutable('2026-10-27 10:00:00'), "From: s@example.org\nTo: r@example.org\nSubject: mail {$i}\n\n{$padding}\n");
        }
        self::assertSame(30, $this->repository->count(MailFilter::all()));

        // Same-length damage to mail 13 outside the fingerprinted tail keeps the index valid but makes a full load of that mail fail.
        $mtime = (int) filemtime($this->path);
        $contents = (string) file_get_contents($this->path);
        $position = (int) strpos($contents, 'Subject: mail 13');
        file_put_contents($this->path, substr_replace($contents, 'Subject: mail XX', $position, 16));
        touch($this->path, $mtime);

        $entries = $this->repository->findEntries(MailFilter::all(), 10, 10);

        self::assertSame(
            array_map(static fn (int $i): string => 'mail '.$i, range(20, 11)),
            array_map(static fn (MessageIndexEntry $entry): string => $entry->subject, $entries),
        );
        self::assertCount(9, $this->repository->find(MailFilter::all(), 10, 10));
    }

    #[Test]
    public function staleV1CacheIsIgnored(): void
    {
        $this->append('one');
        $cache = $this->get(CacheManager::class)->getCache('typo3_letterbox_index');
        $mailbox = new Mailbox($this->path, new MboxFormat());
        $cache->set('v1_'.hash('sha256', $this->path), ['fingerprint' => $mailbox->fingerprint(), 'entries' => []]);

        self::assertSame(1, $this->repository->count(MailFilter::all()));
    }

    #[Test]
    public function indexExtendsWhenFileOnlyGrew(): void
    {
        $this->append('one');
        self::assertSame(1, $this->repository->count(MailFilter::all()));

        $this->append('two');

        self::assertSame(['two', 'one'], $this->subjects($this->repository->find(MailFilter::all(), 0, 10)));
    }

    #[Test]
    public function findByIdAndAttachmentLookup(): void
    {
        $this->append('one');
        $mail = $this->repository->find(MailFilter::all(), 0, 1)[0];

        self::assertSame('one', $this->repository->findById($mail->id)?->subject);
        self::assertSame('a.txt', $this->repository->attachment($mail->id, 0)?->filename);
        self::assertNull($this->repository->attachment($mail->id, 5));
    }

    #[Test]
    public function findByIdReturnsNullForUnknownId(): void
    {
        $this->append('one');

        self::assertNull($this->repository->findById('0123456789abcdef0123456789abcdef'));
    }

    #[Test]
    public function deleteRemovesOnlyThatMail(): void
    {
        foreach (['one', 'two', 'three'] as $subject) {
            $this->append($subject);
        }
        $two = $this->repository->find(MailFilter::all(), 1, 1)[0];

        self::assertTrue($this->repository->delete($two->id));

        self::assertSame(['three', 'one'], $this->subjects($this->repository->find(MailFilter::all(), 0, 10)));
        self::assertFalse($this->repository->delete($two->id));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function deletedPositions(): iterable
    {
        yield 'first in the file' => [3];
        yield 'in the middle' => [1];
        yield 'last in the file' => [0];
    }

    #[Test]
    #[DataProvider('deletedPositions')]
    public function deleteKeepsTheIndexWithoutReadingTheMailboxAgain(int $position): void
    {
        foreach (['one', 'two', 'three', 'four'] as $subject) {
            $this->append($subject);
        }
        $entry = $this->repository->findEntries(MailFilter::all(), $position, 1)[0];

        self::assertTrue($this->repository->delete($entry->id));

        $this->assertCachedIndexIsCurrent(3);
    }

    #[Test]
    public function pruneKeepsTheIndexWithoutReadingTheMailboxAgain(): void
    {
        $now = self::now();
        $this->appendReceived('old', $now->modify('-40 days'));
        foreach (['one', 'two', 'three', 'four'] as $subject) {
            $this->appendReceived($subject, $now->modify('-1 day'));
        }
        $this->repository->count(MailFilter::all());

        self::assertSame(1, $this->repository->prune(0, 30, $now));
        $this->assertCachedIndexIsCurrent(4);
        self::assertSame(2, $this->repository->prune(2, 0, $now));
        $this->assertCachedIndexIsCurrent(2);
        self::assertSame(['four', 'three'], $this->entrySubjects());
    }

    #[Test]
    public function aMailAppendedByAnotherWriterBeforeTheDeleteIsKept(): void
    {
        foreach (['one', 'two'] as $subject) {
            $this->append($subject);
        }
        $one = $this->repository->findEntries(MailFilter::all(), 1, 1)[0];
        $this->append('three');

        self::assertTrue($this->repository->delete($one->id));

        self::assertSame(['three', 'two'], $this->entrySubjects());
        self::assertSame(['three', 'two'], array_map(static fn (MessageIndexEntry $entry): string => $entry->subject, $this->repositoryWith($this->writer)->findEntries(MailFilter::all(), 0, 10)));
    }

    #[Test]
    public function deleteInExtMboxFormatLeavesTheOtherMails(): void
    {
        file_put_contents($this->path, ExtMboxFixture::contents());
        self::assertSame(['Third', 'Second', 'First'], $this->subjects($this->repository->find(MailFilter::all(), 0, 10)));
        $second = $this->repository->find(MailFilter::all(), 1, 1)[0];

        self::assertTrue($this->repository->delete($second->id));

        self::assertSame(['Third', 'First'], $this->subjects($this->repository->find(MailFilter::all(), 0, 10)));
    }

    #[Test]
    public function clearEmptiesTheMailbox(): void
    {
        $this->append('one');
        self::assertSame(1, $this->repository->count(MailFilter::all()));

        $this->repository->clear();

        self::assertSame(0, $this->repository->count(MailFilter::all()));
    }

    #[Test]
    public function indexIsRebuiltAfterTruncateAndRegrow(): void
    {
        $this->append('old one');
        $this->append('old two');
        self::assertSame(2, $this->repository->count(MailFilter::all()));

        $this->writer->truncate();
        $this->append('brand new subject that is long enough to grow beyond the old size');

        self::assertSame(1, $this->repository->count(MailFilter::all()));
        self::assertSame(['brand new subject that is long enough to grow beyond the old size'], $this->subjects($this->repository->find(MailFilter::all(), 0, 10)));
    }

    #[Test]
    public function indexIsRebuiltAfterRegrowToTheSameSize(): void
    {
        $date = new DateTimeImmutable('2026-10-27 10:00:00');
        $raw = static fn (string $subject): string => "From: s@example.org\nTo: r@example.org\nSubject: {$subject}\n\nbody\n";
        $this->writer->append('s@example.org', $date, $raw('AAAA'));
        self::assertSame(['AAAA'], $this->subjects($this->repository->find(MailFilter::all(), 0, 10)));
        $sizeBefore = $this->repository->sizeInBytes();
        $mtimeBefore = filemtime($this->path);

        $this->writer->truncate();
        $this->writer->append('s@example.org', $date, $raw('BBBB'));
        touch($this->path, (int) $mtimeBefore);

        self::assertSame($sizeBefore, $this->repository->sizeInBytes());
        $mails = $this->repository->find(MailFilter::all(), 0, 10);
        self::assertSame(['BBBB'], $this->subjects($mails));
        self::assertSame('BBBB', $this->repository->findById($mails[0]->id)?->subject);
    }

    #[Test]
    public function unconfiguredMailboxYieldsEmptyRepository(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] = '';

        $repository = $this->get(MailRepository::class);

        self::assertFalse($repository->isConfigured());
        self::assertSame(0, $repository->count(MailFilter::all()));
        self::assertSame([], $repository->find(MailFilter::all(), 0, 10));
        self::assertSame(0, $repository->sizeInBytes());
    }

    #[Test]
    public function configuredRepositoryReportsConfigured(): void
    {
        self::assertTrue($this->repository->isConfigured());
    }

    #[Test]
    public function quickFilterCountsApplyTheQueryToEveryQuickFilter(): void
    {
        $this->append('Reset one');
        $this->append('Reset two');
        $this->append('Newsletter');
        $this->writer->append('s@example.org', new DateTimeImmutable('2026-10-27 10:00:00'), "From: s@example.org\nTo: r@example.org\nSubject: Reset plain\n\nbody\n");
        $readId = $this->repository->findEntries(MailFilter::all(), 3, 1)[0]->id;

        $counts = $this->repository->quickFilterCounts(MailFilter::create('reset', QuickFilter::Unread, [$readId]));

        self::assertSame(['all' => 3, 'unread' => 2, 'attachments' => 2], $counts);
    }

    #[Test]
    public function latestReturnsNewestMatching(): void
    {
        $this->appendTo('old', 'anna@customer.example');
        $this->appendTo('newest other', 'bob@other.example');

        self::assertSame('old', $this->repository->latest(MailFilter::all()->withRecipient('anna@customer.example'))?->subject);
        self::assertSame('newest other', $this->repository->latest(MailFilter::all())?->subject);
        self::assertNull($this->repository->latest(MailFilter::all()->withRecipient('nobody@example.org')));
    }

    #[Test]
    public function pruneKeepsNewestMaxMessages(): void
    {
        foreach (['one', 'two', 'three', 'four', 'five'] as $subject) {
            $this->appendReceived($subject, self::now());
        }

        self::assertTrue($this->repository->needsPruning(2, 0, self::now()));
        self::assertSame(3, $this->repository->prune(2, 0, self::now()));

        self::assertSame(['five', 'four'], $this->entrySubjects());
        self::assertFalse($this->repository->needsPruning(2, 0, self::now()));
        self::assertSame(0, $this->repository->prune(2, 0, self::now()));
    }

    #[Test]
    public function pruneRemovesOlderThanMaxAgeDays(): void
    {
        $this->appendReceived('old', self::now()->modify('-31 days'));
        $this->appendReceived('almost old', self::now()->modify('-29 days'));
        $this->appendReceived('new', self::now());

        self::assertTrue($this->repository->needsPruning(0, 30, self::now()));
        self::assertSame(1, $this->repository->prune(0, 30, self::now()));

        self::assertSame(['new', 'almost old'], $this->entrySubjects());
        self::assertFalse($this->repository->needsPruning(0, 30, self::now()));
    }

    #[Test]
    public function zeroDisablesEachLimit(): void
    {
        foreach (['one', 'two', 'three'] as $subject) {
            $this->appendReceived($subject, self::now()->modify('-100 days'));
        }

        self::assertFalse($this->repository->needsPruning(0, 0, self::now()));
        self::assertSame(0, $this->repository->prune(0, 0, self::now()));
        self::assertSame(0, $this->repository->prune(3, 0, self::now()));
        self::assertSame(['three', 'two', 'one'], $this->entrySubjects());

        self::assertSame(2, $this->repository->prune(1, 0, self::now()));
        self::assertSame(1, $this->repository->prune(0, 30, self::now()));
        self::assertSame([], $this->entrySubjects());
    }

    #[Test]
    public function mailWithoutReceivedIsOnlyCountedNotAged(): void
    {
        file_put_contents($this->path, "From old@example.org Mon Feb 31 10:00:00 2016\nFrom: old@example.org\nSubject: undated\n\nbody\n\n");
        $this->appendReceived('recent', self::now());
        self::assertNull($this->repository->findEntries(MailFilter::all(), 1, 1)[0]->received);

        self::assertFalse($this->repository->needsPruning(0, 30, self::now()));
        self::assertSame(0, $this->repository->prune(0, 30, self::now()));
        self::assertSame(['recent', 'undated'], $this->entrySubjects());

        self::assertSame(1, $this->repository->prune(1, 30, self::now()));
        self::assertSame(['recent'], $this->entrySubjects());
    }

    #[Test]
    public function pruneLeavesConcurrentlyAppendedMailAlone(): void
    {
        foreach (['one', 'two', 'three'] as $subject) {
            $this->appendReceived($subject, self::now());
        }
        $format = new MboxFormat();
        // Another process appends after the prune decision, right before this process gets the lock.
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->expects(self::once())->method('acquire')->willReturnCallback(function () use ($format): bool {
            file_put_contents($this->path, $format->separatorLine('s@example.org', self::now()).$format->encode("From: s@example.org\nSubject: four\n\nbody\n"), \FILE_APPEND);

            return true;
        });
        $locker->method('release')->willReturn(true);
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')->willReturn($locker);
        $repository = $this->repositoryWith(new MailboxWriter($this->path, $format, new MailboxLock($lockFactory)));

        self::assertSame(2, $repository->prune(1, 0, self::now()));

        self::assertSame(['four', 'three'], $this->entrySubjects());
    }

    /**
     * The cached index has to describe the file as it is now, and match what a full scan yields.
     */
    private function assertCachedIndexIsCurrent(int $count): void
    {
        $mailbox = new Mailbox($this->path, new MboxFormat());
        $cached = $this->get(CacheManager::class)->getCache('typo3_letterbox_index')->get('v8_'.hash('sha256', $this->path));
        $scanned = (new MailIndex($mailbox, new MessageParser(), new VariableFrontend('letterbox_scan', new TransientMemoryBackend())))->entries();

        self::assertIsArray($cached);
        self::assertSame($mailbox->fingerprint(), $cached['fingerprint']);
        self::assertCount($count, $scanned);
        self::assertEquals($scanned, $this->repositoryWith($this->writer)->findEntries(MailFilter::all(), 0, 100));
    }

    private function repositoryWith(MailboxWriter $writer): MailRepository
    {
        $mailbox = new Mailbox($this->path, new MboxFormat());
        $parser = new MessageParser();

        return new MailRepository(new MailIndex($mailbox, $parser, $this->get(CacheManager::class)->getCache('typo3_letterbox_index')), $mailbox, $parser, $writer);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-28 12:00:00', new DateTimeZone('UTC'));
    }

    private function appendReceived(string $subject, DateTimeImmutable $received): void
    {
        $this->writer->append('s@example.org', $received, "From: s@example.org\nTo: r@example.org\nSubject: {$subject}\n\nbody\n");
    }

    /**
     * @return list<string> newest first
     */
    private function entrySubjects(): array
    {
        return array_map(static fn (MessageIndexEntry $entry): string => $entry->subject, $this->repository->findEntries(MailFilter::all(), 0, 100));
    }

    private function appendTo(string $subject, string $to): void
    {
        $raw = "From: s@example.org\nTo: {$to}\nSubject: {$subject}\nMessage-ID: <".bin2hex(random_bytes(4))."@example.org>\n\nbody\n";
        $this->writer->append('s@example.org', new DateTimeImmutable('2026-10-27 10:00:00'), $raw);
    }

    private function append(string $subject): void
    {
        $raw = "From: s@example.org\nTo: r@example.org\nSubject: {$subject}\nMessage-ID: <".bin2hex(random_bytes(4))."@example.org>\n"
            ."MIME-Version: 1.0\nContent-Type: multipart/mixed; boundary=\"b\"\n\n--b\nContent-Type: text/plain\n\nbody\n--b\n"
            ."Content-Type: text/plain; name=\"a.txt\"\nContent-Disposition: attachment; filename=\"a.txt\"\n\nattached\n--b--\n";
        $this->writer->append('s@example.org', new DateTimeImmutable('2026-10-27 10:00:00'), $raw);
    }

    /**
     * @param list<\KonradMichalik\Typo3Letterbox\Domain\Model\Mail> $mails
     *
     * @return list<string>
     */
    private function subjects(array $mails): array
    {
        return array_map(static fn ($mail): string => $mail->subject, $mails);
    }
}
