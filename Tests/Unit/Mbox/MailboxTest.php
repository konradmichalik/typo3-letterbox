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
use DateTimeZone;
use KonradMichalik\Typo3Letterbox\Mbox\{Mailbox, MailboxLock, MailboxWriter, MboxFormat, MessageSpan};
use KonradMichalik\Typo3Letterbox\Tests\Unit\Mbox\Fixtures\ExtMboxFixture;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Core\{ApplicationContext, Environment};
use TYPO3\CMS\Core\Locking\{LockFactory, LockingStrategyInterface};

use function dirname;
use function strlen;

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

    /** @var array<string, mixed> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/letterbox-mailbox-'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o777, true);
        $this->previousEnvironment = (new ReflectionClass(Environment::class))->getStaticProperties();
        // GeneralUtility::fixPermissions() only touches paths below the project path.
        Environment::initialize(new ApplicationContext('Testing'), true, true, $this->tempDir, $this->tempDir, $this->tempDir, $this->tempDir, 'index.php', 'UNIX');
    }

    protected function tearDown(): void
    {
        $environment = new ReflectionClass(Environment::class);
        foreach ($this->previousEnvironment as $name => $value) {
            $environment->setStaticPropertyValue($name, $value);
        }
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
    public function splitsMailboxesWrittenByExtMboxWithUnpaddedDays(): void
    {
        file_put_contents($this->tempPath(), ExtMboxFixture::contents());
        $box = new Mailbox($this->tempPath(), new MboxFormat());
        $spans = iterator_to_array($box->spans(), false);

        self::assertCount(3, $spans);
        foreach (ExtMboxFixture::SUBJECTS as $index => $subject) {
            self::assertStringContainsString('Subject: '.$subject."\r\n", $box->read($spans[$index]));
        }
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
    public function readWithReceivedReturnsTheMessageAndTheSeparatorTime(): void
    {
        $path = $this->tempPath();
        $raw = "Subject: x\n\nbody\n";
        $this->writer($path)->append('a@example.org', new DateTimeImmutable('2026-10-27 10:00:05', new DateTimeZone('UTC')), $raw);
        $box = new Mailbox($path, new MboxFormat());

        [$message, $received] = $box->readWithReceived(iterator_to_array($box->spans(), false)[0]);

        self::assertSame($raw, $message);
        self::assertSame('2026-10-27 10:00:05', $received?->format('Y-m-d H:i:s'));
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
    public function messagesReadLikeReadWithReceivedAndStopAtTheGivenOffset(): void
    {
        $path = $this->tempPath();
        foreach (["Subject: one\n\n>From quoted\n\n\n", "Subject: two\n\nbody", "Subject: three\n\nbody\n"] as $raw) {
            $this->writer($path)->append('a@example.org', new DateTimeImmutable('2026-10-27 10:00:05'), $raw);
        }
        $box = new Mailbox($path, new MboxFormat());
        $spans = iterator_to_array($box->spans(), false);

        $messages = iterator_to_array($box->messages($spans[1]->offset, $spans[2]->offset), false);
        $all = iterator_to_array($box->messages(0, \PHP_INT_MAX), false);

        self::assertEquals([[$spans[1], ...$box->readWithReceived($spans[1])]], $messages);
        self::assertEquals(array_map(static fn (MessageSpan $span): array => [$span, ...$box->readWithReceived($span)], $spans), $all);
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
    public function removeWithSpanPastEndThrowsAndKeepsFile(): void
    {
        $path = $this->tempPath();
        $writer = $this->writer($path);
        $writer->append('a@example.org', new DateTimeImmutable(), "Subject: One\n\nb\n");
        $writer->append('a@example.org', new DateTimeImmutable(), "Subject: Two\n\nb\n");
        $before = (string) file_get_contents($path);
        $first = iterator_to_array((new Mailbox($path, new MboxFormat()))->spans(), false)[0];

        try {
            $writer->remove([new MessageSpan($first->offset, strlen($before) + 100)]);
            self::fail('Exception expected');
        } catch (RuntimeException $e) {
            self::assertStringContainsString($path, $e->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
        self::assertSame([$path], glob($this->tempDir.'/*'));
    }

    #[Test]
    public function removeWithStaleSpanThrowsBeforeWriting(): void
    {
        $path = $this->tempPath();
        $writer = $this->writer($path);
        $writer->append('a@example.org', new DateTimeImmutable(), "Subject: One\n\nbody\n");
        $before = (string) file_get_contents($path);

        try {
            $writer->remove([new MessageSpan(5, 10)]);
            self::fail('Exception expected');
        } catch (RuntimeException $e) {
            self::assertStringContainsString($path, $e->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
        self::assertSame([$path], glob($this->tempDir.'/*'));
    }

    #[Test]
    #[DataProvider('unterminatedMailboxes')]
    public function appendRepairsMissingTerminator(string $existing): void
    {
        $path = $this->tempPath();
        file_put_contents($path, $existing);
        $raw = "Subject: New\n\nFrom body\n";

        $this->writer($path)->append('a@example.org', new DateTimeImmutable(), $raw);

        $box = new Mailbox($path, new MboxFormat());
        $spans = iterator_to_array($box->spans(), false);
        self::assertCount(2, $spans);
        self::assertSame("Subject: Old\n\nold body\n", $box->read($spans[0]));
        self::assertSame($raw, $box->read($spans[1]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unterminatedMailboxes(): iterable
    {
        $head = "From old@example.org Tue Oct 27 10:00:00 2026\nSubject: Old\n\nold body";

        yield 'no trailing newline' => [$head];
        yield 'single trailing newline' => [$head."\n"];
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
