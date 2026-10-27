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
use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\Mbox\{MailIndex, Mailbox, MailboxLock, MailboxWriter, MboxFormat, MessageParser};
use KonradMichalik\Typo3Letterbox\Tests\Unit\Mbox\Fixtures\ExtMboxFixture;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\LockFactory;
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

        $format = new MboxFormat();
        $mailbox = new Mailbox($this->path, $format);
        $parser = new MessageParser();
        $cache = $this->get(CacheManager::class)->getCache('typo3_letterbox_index');
        $cache->flush();
        $this->writer = new MailboxWriter($this->path, $format, new MailboxLock($this->get(LockFactory::class)));
        $this->repository = new MailRepository(new MailIndex($mailbox, $parser, $cache), $mailbox, $parser, $this->writer);
    }

    #[Test]
    public function missingFileCountsZero(): void
    {
        self::assertSame(0, $this->repository->count(MailFilter::all()));
        self::assertSame([], $this->repository->find(MailFilter::all(), 0, 10));
        self::assertNull($this->repository->findById('0123456789abcdef0123456789abcdef', MailFilter::all()));
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

        self::assertSame('one', $this->repository->findById($mail->id, MailFilter::all())?->subject);
        self::assertSame('a.txt', $this->repository->attachment($mail->id, 0, MailFilter::all())?->filename);
        self::assertNull($this->repository->attachment($mail->id, 5, MailFilter::all()));
    }

    #[Test]
    public function findByIdReturnsNullForUnknownId(): void
    {
        $this->append('one');

        self::assertNull($this->repository->findById('0123456789abcdef0123456789abcdef', MailFilter::all()));
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
        self::assertSame('BBBB', $this->repository->findById($mails[0]->id, MailFilter::all())?->subject);
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
