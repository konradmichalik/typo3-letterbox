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

use DateTimeImmutable;
use KonradMichalik\Typo3Letterbox\Mbox\{Mailbox, MailboxLock, MailboxWriter, MboxFormat};
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Core\{ApplicationContext, Environment};
use TYPO3\CMS\Core\Locking\{LockFactory, LockingStrategyInterface};

use function dirname;

/**
 * MailboxTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MailboxTest extends TestCase
{
    private string $tempDir = '';

    private int $acquired = 0;

    private int $released = 0;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/letterbox-mailbox-'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o777, true);
        // GeneralUtility::fixPermissions() only touches paths below the project path.
        Environment::initialize(new ApplicationContext('Testing'), true, true, $this->tempDir, $this->tempDir, $this->tempDir, $this->tempDir, 'index.php', 'UNIX');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    #[Test]
    public function splitsOnlyOnSeparatorsAfterEmptyLine(): void
    {
        self::assertCount(1, iterator_to_array($this->mailbox('body-with-from-line.mbox')->spans(), false));
    }

    #[Test]
    public function readsTwoMailsAndTheirBodies(): void
    {
        $box = $this->mailbox('two-mails.mbox');
        $spans = iterator_to_array($box->spans(), false);

        self::assertCount(2, $spans);
        self::assertStringContainsString('Subject: Second', $box->read($spans[1]));
        self::assertSame("Subject: First\n\nHello one\n", $box->read($spans[0]));
    }

    #[Test]
    public function keepsEscapedFromLineDecodedInBody(): void
    {
        $box = $this->mailbox('body-with-from-line.mbox');
        $raw = $box->read(iterator_to_array($box->spans(), false)[0]);

        self::assertStringContainsString("\nFrom escaped@example.org Tue Oct 27 10:00:00 2026\n", $raw);
    }

    #[Test]
    public function spansCanStartAtAnOffset(): void
    {
        $box = $this->mailbox('two-mails.mbox');
        $all = iterator_to_array($box->spans(), false);
        $rest = iterator_to_array($box->spans($all[1]->offset), false);

        self::assertEquals([$all[1]], $rest);
    }

    #[Test]
    public function missingFileYieldsNothing(): void
    {
        $box = new Mailbox('/nonexistent/x.mbox', new MboxFormat());

        self::assertSame([], iterator_to_array($box->spans(), false));
        self::assertFalse($box->exists());
        self::assertSame('', $box->fingerprint());
    }

    #[Test]
    public function emptyFileYieldsNothing(): void
    {
        self::assertSame([], iterator_to_array($this->mailbox('empty.mbox')->spans(), false));
    }

    #[Test]
    public function fingerprintChangesWhenFileChanges(): void
    {
        $path = $this->tempPath();
        $writer = $this->writer($path);
        $writer->append('a@example.org', new DateTimeImmutable(), "Subject: 1\n\nb\n");
        $before = (new Mailbox($path, new MboxFormat()))->fingerprint();
        $writer->append('a@example.org', new DateTimeImmutable(), "Subject: 2\n\nb\n");

        self::assertNotSame('', $before);
        self::assertNotSame($before, (new Mailbox($path, new MboxFormat()))->fingerprint());
    }

    #[Test]
    public function writerRoundTripKeepsBodyWithFromLine(): void
    {
        $path = $this->tempPath();
        $raw = "Subject: x\n\nFrom me, with love\n";
        $this->writer($path)->append('a@example.org', new DateTimeImmutable(), $raw);
        $box = new Mailbox($path, new MboxFormat());

        self::assertSame($raw, $box->read(iterator_to_array($box->spans(), false)[0]));
    }

    #[Test]
    public function readMatchesDecodeOfEncode(): void
    {
        $path = $this->tempPath();
        $format = new MboxFormat();
        $raw = "Subject: x\r\n\r\n>From quoted\r\nFrom bare line\r\n\r\n\r\n";
        $this->writer($path)->append('a@example.org', new DateTimeImmutable(), $raw);
        $box = new Mailbox($path, $format);

        self::assertSame($format->decode($format->encode($raw)), $box->read(iterator_to_array($box->spans(), false)[0]));
    }

    #[Test]
    public function appendAcquiresAndReleasesLock(): void
    {
        $this->writer($this->tempPath())->append('a@example.org', new DateTimeImmutable(), "Subject: x\n\nb\n");

        self::assertSame(1, $this->acquired);
        self::assertSame(1, $this->released);
    }

    #[Test]
    public function lockIsReleasedWhenCallbackThrows(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            $this->lock()->run(static function (): never {
                throw new RuntimeException('boom', 3086341176);
            });
        } finally {
            self::assertSame(1, $this->released);
        }
    }

    #[Test]
    public function appendToUnwritablePathThrowsWithPath(): void
    {
        $path = '/nonexistent-dir/x.mbox';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($path);

        $this->writer($path)->append('a@example.org', new DateTimeImmutable(), "Subject: x\n\nb\n");
    }

    #[Test]
    public function removeKeepsOtherMails(): void
    {
        $path = $this->tempPath();
        $writer = $this->writer($path);
        foreach (['One', 'Two', 'Three'] as $subject) {
            $writer->append('a@example.org', new DateTimeImmutable(), "Subject: {$subject}\n\nFrom body\n");
        }
        $box = new Mailbox($path, new MboxFormat());
        $spans = iterator_to_array($box->spans(), false);

        $writer->remove([$spans[1]]);

        $box = new Mailbox($path, new MboxFormat());
        $remaining = array_map($box->read(...), iterator_to_array($box->spans(), false));
        self::assertSame(["Subject: One\n\nFrom body\n", "Subject: Three\n\nFrom body\n"], $remaining);
        self::assertSame([$path], glob($this->tempDir.'/*'));
    }

    #[Test]
    public function truncateEmptiesTheFile(): void
    {
        $path = $this->tempPath();
        $writer = $this->writer($path);
        $writer->append('a@example.org', new DateTimeImmutable(), "Subject: x\n\nb\n");

        $writer->truncate();

        self::assertSame(0, filesize($path));
        self::assertSame([], iterator_to_array((new Mailbox($path, new MboxFormat()))->spans(), false));
    }

    private function mailbox(string $fixture): Mailbox
    {
        return new Mailbox(dirname(__DIR__).'/Mbox/Fixtures/'.$fixture, new MboxFormat());
    }

    private function tempPath(): string
    {
        return $this->tempDir.'/mails.mbox';
    }

    private function lock(): MailboxLock
    {
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturnCallback(function (): bool {
            ++$this->acquired;

            return true;
        });
        $locker->method('release')->willReturnCallback(function (): bool {
            ++$this->released;

            return true;
        });
        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLocker')->with('mbox')->willReturn($locker);

        return new MailboxLock($factory);
    }

    private function writer(string $path): MailboxWriter
    {
        return new MailboxWriter($path, new MboxFormat(), $this->lock());
    }
}
