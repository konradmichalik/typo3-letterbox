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
use Generator;
use RuntimeException;

use function sprintf;
use function strlen;

/**
 * Mailbox.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class Mailbox
{
    private const FINGERPRINT_TAIL_BYTES = 65536;

    public function __construct(
        private string $path,
        private MboxFormat $format,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * @return Generator<MessageSpan>
     */
    public function spans(int $fromOffset = 0): Generator
    {
        if (!$this->isReadable()) {
            return;
        }

        $handle = fopen($this->path, 'r');
        if (false === $handle) {
            return;
        }

        try {
            fseek($handle, $fromOffset);
            yield from $this->scan($handle, $fromOffset);
        } finally {
            fclose($handle);
        }
    }

    public function read(MessageSpan $span): string
    {
        return $this->readWithReceived($span)[0];
    }

    /**
     * Reads the message and its arrival time from the separator line in one pass.
     *
     * @return array{0: string, 1: ?DateTimeImmutable}
     */
    public function readWithReceived(MessageSpan $span): array
    {
        $handle = $this->isReadable() ? fopen($this->path, 'r') : false;
        if (false === $handle) {
            throw new RuntimeException(sprintf('Cannot open mailbox "%s" for reading.', $this->path), 2941090058);
        }

        try {
            fseek($handle, $span->offset);
            $separator = $span->length > 0 ? (string) fgets($handle, $span->length + 1) : '';
            if (!str_ends_with($separator, "\n")) {
                return [$this->format->decode(''), null];
            }

            // Leaving the final newline of a stored message unread is what decode() would cut, without copying the mail.
            $length = $span->length - strlen($separator);
            if ($length >= 2 && 0 === fseek($handle, $span->offset + $span->length - 2) && "\n\n" === fread($handle, 2)) {
                --$length;
            }
            fseek($handle, $span->offset + strlen($separator));
            $stored = $length > 0 ? (string) fread($handle, $length) : '';
        } finally {
            fclose($handle);
        }

        return [$this->format->unescape($stored), $this->format->received($separator)];
    }

    public function fingerprint(): string
    {
        clearstatcache(true, $this->path);
        $stat = $this->isReadable() ? stat($this->path) : false;
        $handle = $this->isReadable() ? fopen($this->path, 'r') : false;
        if (false === $stat || false === $handle) {
            return '';
        }

        $firstLine = (string) fgets($handle);
        fseek($handle, max(0, $stat['size'] - self::FINGERPRINT_TAIL_BYTES));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        // The tail covers a file rewritten to the same size within the same second, which size and mtime cannot tell apart.
        return sprintf('%d|%d|%d|%s|%s', $stat['size'], $stat['mtime'], $stat['ino'], hash('sha256', $firstLine), hash('sha256', $tail));
    }

    private function isReadable(): bool
    {
        return is_file($this->path) && is_readable($this->path);
    }

    /**
     * @param resource $handle
     *
     * @return Generator<MessageSpan>
     */
    private function scan($handle, int $fromOffset): Generator
    {
        $position = $fromOffset;
        $start = null;
        $previousEmpty = true;
        while (false !== ($line = fgets($handle))) {
            if ($previousEmpty && $this->format->isSeparator($line)) {
                if (null !== $start) {
                    yield new MessageSpan($start, $position - $start);
                }
                $start = $position;
            }
            $previousEmpty = "\n" === $line;
            $position += strlen($line);
        }

        if (null !== $start) {
            yield new MessageSpan($start, $position - $start);
        }
    }
}
