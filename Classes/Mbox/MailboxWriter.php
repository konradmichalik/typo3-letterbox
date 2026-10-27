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
        $entry = $this->format->separatorLine($sender, $date).$this->format->encode($rfc822);

        $this->lock->run(function () use ($entry): void {
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
            $source = is_readable($this->path) ? fopen($this->path, 'r') : false;
            if (false === $source) {
                throw new RuntimeException(sprintf('Cannot open mailbox "%s" for reading.', $this->path), 8800415279);
            }

            $temp = $this->path.'.'.bin2hex(random_bytes(6)).'.tmp';
            $target = $this->isWritable() ? fopen($temp, 'w') : false;
            if (false === $target) {
                fclose($source);
                throw new RuntimeException(sprintf('Cannot write temporary file for mailbox "%s".', $this->path), 2593671261);
            }

            try {
                $cursor = 0;
                foreach ($spans as $span) {
                    $this->copy($source, $target, $cursor, $span->offset - $cursor);
                    $cursor = $span->offset + $span->length;
                }
                fseek($source, $cursor);
                stream_copy_to_stream($source, $target);
            } catch (Throwable $e) {
                fclose($source);
                fclose($target);
                unlink($temp);
                throw $e;
            }

            fclose($source);
            fclose($target);

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

    /**
     * @param resource $source
     * @param resource $target
     */
    private function copy($source, $target, int $offset, int $length): void
    {
        if ($length <= 0) {
            return;
        }
        fseek($source, $offset);
        stream_copy_to_stream($source, $target, $length);
    }
}
