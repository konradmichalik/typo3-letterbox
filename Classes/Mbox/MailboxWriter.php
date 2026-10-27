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

namespace KonradMichalik\Typo3Letterbox\Mbox;

use DateTimeImmutable;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function dirname;
use function sprintf;
use function strlen;

/**
 * MailboxWriter.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MailboxWriter
{
    public function __construct(
        private string $path,
        private MboxFormat $format,
        private MailboxLock $lock,
    ) {}

    public function append(string $sender, DateTimeImmutable $date, string $rfc822): void
    {
        $message = $this->format->separatorLine($sender, $date).$this->format->encode($rfc822);

        $this->lock->run(function () use ($message): void {
            $entry = $this->terminator().$message;
            $handle = $this->isWritable() ? fopen($this->path, 'a') : false;
            if (false === $handle) {
                throw new RuntimeException(sprintf('Cannot open mailbox "%s" for writing.', $this->path), 9787691864);
            }

            try {
                $written = fwrite($handle, $entry);
            } finally {
                fclose($handle);
            }

            if (strlen($entry) !== $written) {
                throw new RuntimeException(sprintf('Cannot write to mailbox "%s".', $this->path), 8094390312);
            }

            GeneralUtility::fixPermissions($this->path);
        });
    }

    /**
     * @param list<MessageSpan> $spans
     */
    public function remove(array $spans): void
    {
        if ([] === $spans) {
            return;
        }

        usort($spans, static fn (MessageSpan $a, MessageSpan $b): int => $a->offset <=> $b->offset);

        $this->lock->run(function () use ($spans): void {
            $temp = $this->path.'.'.bin2hex(random_bytes(6)).'.tmp';

            try {
                $this->writeWithout($spans, $temp);
            } catch (Throwable $e) {
                if (is_file($temp)) {
                    unlink($temp);
                }
                throw $e;
            }

            if (!rename($temp, $this->path)) {
                unlink($temp);
                throw new RuntimeException(sprintf('Cannot replace mailbox "%s".', $this->path), 3176849210);
            }

            GeneralUtility::fixPermissions($this->path);
        });
    }

    public function truncate(): void
    {
        $this->lock->run(function (): void {
            $handle = $this->isWritable() ? fopen($this->path, 'w') : false;
            if (false === $handle) {
                throw new RuntimeException(sprintf('Cannot open mailbox "%s" for writing.', $this->path), 8293706140);
            }
            fclose($handle);
        });
    }

    private function isWritable(): bool
    {
        return is_file($this->path) ? is_writable($this->path) : is_writable(dirname($this->path));
    }

    private function terminator(): string
    {
        $size = is_file($this->path) ? (int) filesize($this->path) : 0;
        if (0 === $size) {
            return '';
        }

        $handle = is_readable($this->path) ? fopen($this->path, 'r') : false;
        if (false === $handle) {
            throw new RuntimeException(sprintf('Cannot open mailbox "%s" for reading.', $this->path), 4417290635);
        }

        fseek($handle, max(0, $size - 2));
        $tail = (string) fread($handle, 2);
        fclose($handle);

        return match (true) {
            str_ends_with($tail, "\n\n") => '',
            str_ends_with($tail, "\n") => "\n",
            default => "\n\n",
        };
    }

    /**
     * @param list<MessageSpan> $spans sorted by offset
     */
    private function writeWithout(array $spans, string $temp): void
    {
        $this->assertSpansAreCurrent($spans);

        $source = is_readable($this->path) ? fopen($this->path, 'r') : false;
        if (false === $source) {
            throw new RuntimeException(sprintf('Cannot open mailbox "%s" for reading.', $this->path), 8800415279);
        }
        $target = $this->isWritable() ? fopen($temp, 'w') : false;
        if (false === $target) {
            fclose($source);
            throw new RuntimeException(sprintf('Cannot write temporary file for mailbox "%s".', $this->path), 2593671261);
        }

        try {
            $size = (int) filesize($this->path);

            $copy = function (int $offset, int $length) use ($source, $target): void {
                if ($length <= 0) {
                    return;
                }
                $copied = 0 === fseek($source, $offset) ? stream_copy_to_stream($source, $target, $length) : false;
                if ($copied !== $length) {
                    throw new RuntimeException(sprintf('Cannot copy %d bytes at offset %d of mailbox "%s".', $length, $offset, $this->path), 5290174836);
                }
            };

            $cursor = 0;
            foreach ($spans as $span) {
                $copy($cursor, $span->offset - $cursor);
                $cursor = max($cursor, $span->offset + $span->length);
            }
            $copy($cursor, $size - $cursor);
        } finally {
            fclose($source);
            if (!fclose($target)) {
                throw new RuntimeException(sprintf('Cannot write temporary file for mailbox "%s".', $this->path), 6402518838);
            }
        }
    }

    /**
     * @param list<MessageSpan> $spans
     */
    private function assertSpansAreCurrent(array $spans): void
    {
        $size = (int) filesize($this->path);
        $handle = is_readable($this->path) ? fopen($this->path, 'r') : false;
        if (false === $handle) {
            throw new RuntimeException(sprintf('Cannot open mailbox "%s" for reading.', $this->path), 8800415279);
        }

        try {
            foreach ($spans as $span) {
                $line = 0 === fseek($handle, $span->offset) ? fgets($handle) : false;
                if ($span->length < 0 || $span->offset + $span->length > $size || false === $line || !$this->format->isSeparator($line)) {
                    throw new RuntimeException(sprintf('Span at offset %d is stale for mailbox "%s".', $span->offset, $this->path), 7120936482);
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
