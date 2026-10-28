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
            $bytes = $span->length > 0 ? fread($handle, $span->length) : '';
        } finally {
            fclose($handle);
        }

        $bytes = false === $bytes ? '' : $bytes;
        $separatorEnd = strpos($bytes, "\n");
        if (false === $separatorEnd) {
            return [$this->format->decode(''), null];
        }

        return [
            $this->format->decode(substr($bytes, $separatorEnd + 1)),
            $this->format->received(substr($bytes, 0, $separatorEnd + 1)),
        ];
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
