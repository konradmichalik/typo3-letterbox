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
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

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

    private function index(): MailIndex
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);

        return new MailIndex(new Mailbox($this->path, new MboxFormat()), new MessageParser(), $cache);
    }
}
