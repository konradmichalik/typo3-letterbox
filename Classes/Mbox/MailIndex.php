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

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

use function is_array;
use function is_string;

/**
 * MailIndex.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MailIndex
{
    private const CACHE_VERSION = 'v3_';

    public function __construct(
        private Mailbox $mailbox,
        private MessageParser $parser,
        private FrontendInterface $cache,
    ) {}

    /**
     * @return list<MessageIndexEntry> newest first, by file order
     */
    public function entries(): array
    {
        $fingerprint = $this->mailbox->fingerprint();
        if ('' === $fingerprint) {
            return [];
        }

        $key = self::CACHE_VERSION.hash('sha256', $this->mailbox->path());
        $cached = $this->cache->get($key);
        $entries = $this->usable($cached, $fingerprint);

        if (null === $entries) {
            $entries = $this->scan(0, self::sizeOf($fingerprint));
            $this->cache->set($key, ['fingerprint' => $fingerprint, 'entries' => $entries]);
        } elseif (is_array($cached) && $cached['fingerprint'] !== $fingerprint) {
            $entries = [...$entries, ...$this->scan(self::sizeOf($cached['fingerprint']), self::sizeOf($fingerprint))];
            $this->cache->set($key, ['fingerprint' => $fingerprint, 'entries' => $entries]);
        }

        return array_reverse($entries);
    }

    /**
     * @return list<MessageIndexEntry>|null the cached entries, or null when they cannot be reused
     */
    private function usable(mixed $cached, string $fingerprint): ?array
    {
        if (!is_array($cached) || !is_string($cached['fingerprint'] ?? null) || !is_array($cached['entries'] ?? null) || !array_is_list($cached['entries'])) {
            return null;
        }
        foreach ($cached['entries'] as $entry) {
            if (!$entry instanceof MessageIndexEntry) {
                return null;
            }
        }

        if ($cached['fingerprint'] === $fingerprint) {
            return $cached['entries'];
        }

        [$size, , $inode, $firstLine] = [...explode('|', $cached['fingerprint']), '', '', '', ''];
        [$newSize, , $newInode, $newFirstLine] = explode('|', $fingerprint);

        $grown = $inode === $newInode && $firstLine === $newFirstLine && (int) $newSize > (int) $size;

        return $grown && $this->endsWithKnownMessage($cached['entries'], (int) $size)
            ? $cached['entries']
            : null;
    }

    /**
     * Inode and first line alone cannot tell a grown file from a truncated one that regrew to a larger size.
     *
     * @param list<MessageIndexEntry> $entries
     */
    private function endsWithKnownMessage(array $entries, int $size): bool
    {
        $last = end($entries);
        if (false === $last) {
            return 0 === $size;
        }

        return $last->span->offset + $last->span->length <= $size
            && $last->id === MessageParser::idOf($this->mailbox->read($last->span));
    }

    /**
     * @return list<MessageIndexEntry>
     */
    private function scan(int $fromOffset, int $untilOffset): array
    {
        $entries = [];
        foreach ($this->mailbox->spans($fromOffset) as $span) {
            // A message appended while scanning belongs to the next run, so it is not indexed twice.
            if ($span->offset >= $untilOffset) {
                break;
            }
            $entries[] = $this->parser->summarize($this->mailbox->read($span), $span);
        }

        return $entries;
    }

    private static function sizeOf(string $fingerprint): int
    {
        return (int) explode('|', $fingerprint)[0];
    }
}
