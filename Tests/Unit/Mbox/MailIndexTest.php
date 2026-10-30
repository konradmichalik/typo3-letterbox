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

use KonradMichalik\Typo3Letterbox\Mbox\{MailIndex, Mailbox, MboxFormat, MessageIndexEntry, MessageParser};
use KonradMichalik\Typo3Letterbox\Tests\Unit\Mbox\Fixtures\ExtMboxFixture;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\{FrontendInterface, VariableFrontend};
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * MailIndexTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MailIndexTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/letterbox-index-'.bin2hex(random_bytes(6)).'.mbox';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    #[Test]
    public function separatorDateIsIndexedAsReceived(): void
    {
        file_put_contents(
            $this->path,
            ExtMboxFixture::contents()
                ."From a@example.org Wed Oct  7 10:42:00 2026\nDate: Fri, 1 Jan 2016 00:00:00 +0000\nSubject: Padded\n\nbody\n\n"
                ."From a@example.org Mon Feb 31 10:00:00 2026\nSubject: Impossible\n\nbody\n\n",
        );

        $received = array_map(
            static fn (MessageIndexEntry $entry): ?string => $entry->received?->format('Y-m-d H:i:s e'),
            $this->index()->entries(),
        );

        self::assertSame([null, '2026-10-07 10:42:00 UTC', '2026-11-03 10:00:00 UTC', '2026-11-02 10:00:00 UTC', '2026-11-01 10:00:00 UTC'], $received);
    }

    #[Test]
    public function theCacheHoldsPlainArraysThatRestoreTheSameEntries(): void
    {
        file_put_contents($this->path, ExtMboxFixture::contents()."From a@example.org Wed Oct  7 10:42:00 2026\nDate: Fri, 1 Jan 2016 00:00:00 +0000\nFrom: \"Shop\" <shop@example.org>\nTo: Anna <anna@example.org>\nCc: b@example.org\nSubject: Last\n\nbody\n\n");
        $cache = new VariableFrontend('letterbox_test', $this->memoryBackend());

        $built = $this->index($cache)->entries();
        $stored = $cache->get('v9_'.hash('sha256', $this->path));
        $restored = $this->index($cache)->entries();

        self::assertIsArray($stored);
        self::assertStringNotContainsString('O:', serialize($stored['entries']));
        self::assertEquals($built, $restored);
        self::assertSame('2016-01-01T00:00:00+00:00', $restored[0]->date?->format(\DATE_ATOM));
        self::assertSame('2026-10-07 10:42:00 UTC', $restored[0]->received?->format('Y-m-d H:i:s e'));
    }

    #[Test]
    public function entriesAreReadFromTheCacheOnceAndFollowAppendsInMemory(): void
    {
        file_put_contents($this->path, ExtMboxFixture::contents());
        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::once())->method('get')->willReturn(false);
        $cache->expects(self::once())->method('set');
        $index = $this->index($cache);

        $first = $index->entries();
        self::assertSame($first, $index->entries());
        file_put_contents($this->path, "From a@example.org Wed Oct  7 10:42:00 2026\nSubject: More\n\nbody\n\n", \FILE_APPEND);

        self::assertSame(['More', 'Third', 'Second', 'First'], array_map(static fn (MessageIndexEntry $entry): string => $entry->subject, $index->entries()));
    }

    private function index(?FrontendInterface $cache = null): MailIndex
    {
        if (null !== $cache) {
            return new MailIndex(new Mailbox($this->path, new MboxFormat()), new MessageParser(), $cache);
        }

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);

        return new MailIndex(new Mailbox($this->path, new MboxFormat()), new MessageParser(), $cache);
    }

    /**
     * TYPO3 13 passes the application context to a cache backend, TYPO3 14 only the options.
     */
    private function memoryBackend(): TransientMemoryBackend
    {
        $arguments = (new Typo3Version())->getMajorVersion() < 14 ? ['Testing'] : [];

        return (new ReflectionClass(TransientMemoryBackend::class))->newInstanceArgs($arguments);
    }
}
