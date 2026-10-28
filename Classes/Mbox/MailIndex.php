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
use DateTimeZone;
use KonradMichalik\Typo3Letterbox\Domain\Model\Address;
use Throwable;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use UnexpectedValueException;

use function count;
use function is_array;
use function is_string;

/**
 * MailIndex.
 *
 * The cache holds plain arrays, which unserialize several times faster than entry objects with dates.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MailIndex
{
    private const CACHE_VERSION = 'v8_';

    /** @var array{fingerprint: string, entries: list<MessageIndexEntry>}|null the index of this request, newest first */
    private ?array $memo = null;

    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly MessageParser $parser,
        private readonly FrontendInterface $cache,
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
        if (null !== $this->memo && $fingerprint === $this->memo['fingerprint']) {
            return $this->memo['entries'];
        }

        $key = $this->cacheKey();
        $cached = $this->cache->get($key);
        $entries = $this->usable($cached, $fingerprint);

        if (null === $entries) {
            $entries = $this->scan(0, self::sizeOf($fingerprint));
            $this->cache->set($key, ['fingerprint' => $fingerprint, 'entries' => array_map(self::toRow(...), $entries)]);
        } elseif (is_array($cached) && $cached['fingerprint'] !== $fingerprint) {
            $added = $this->scan(self::sizeOf($cached['fingerprint']), self::sizeOf($fingerprint));
            $entries = [...$entries, ...$added];
            $this->cache->set($key, ['fingerprint' => $fingerprint, 'entries' => [...$cached['entries'], ...array_map(self::toRow(...), $added)]]);
        }

        $this->memo = ['fingerprint' => $fingerprint, 'entries' => array_reverse($entries)];

        return $this->memo['entries'];
    }

    /**
     * Cuts the removed spans out of the index of this request and moves the later ones up, so the next read does not
     * scan the rewritten file. Call it under the mailbox lock right after the rewrite.
     *
     * @param list<MessageSpan> $removed spans of indexed mails
     */
    public function removed(array $removed): void
    {
        if (null === $this->memo) {
            return;
        }
        $lengths = [];
        foreach ($removed as $span) {
            $lengths[$span->offset] = $span->length;
        }
        $kept = [];
        $shift = 0;
        foreach (array_reverse($this->memo['entries']) as $entry) {
            if (isset($lengths[$entry->span->offset])) {
                $shift += $lengths[$entry->span->offset];
                continue;
            }
            $kept[] = 0 === $shift ? $entry : $entry->withSpan(new MessageSpan($entry->span->offset - $shift, $entry->span->length));
        }

        $fingerprint = $this->mailbox->fingerprint();
        $size = self::sizeOf($fingerprint);
        // Another writer changed the file since the index was read when size or last mail do not fit. The next read scans it then.
        if ('' === $fingerprint || $size !== self::sizeOf($this->memo['fingerprint']) - array_sum($lengths) || !$this->endsWithKnownMessage($kept, $size)) {
            $this->memo = null;

            return;
        }

        $this->cache->set($this->cacheKey(), ['fingerprint' => $fingerprint, 'entries' => array_map(self::toRow(...), $kept)]);
        $this->memo = ['fingerprint' => $fingerprint, 'entries' => array_reverse($kept)];
    }

    /**
     * @return list<MessageIndexEntry>|null the cached entries, or null when they cannot be reused
     */
    private function usable(mixed $cached, string $fingerprint): ?array
    {
        if (!is_array($cached) || !is_string($cached['fingerprint'] ?? null) || !is_array($cached['entries'] ?? null) || !array_is_list($cached['entries'])) {
            return null;
        }
        $entries = self::fromRows($cached['entries']);
        if (null === $entries || $cached['fingerprint'] === $fingerprint) {
            return $entries;
        }

        [$size, , $inode, $firstLine] = [...explode('|', $cached['fingerprint']), '', '', '', ''];
        [$newSize, , $newInode, $newFirstLine] = explode('|', $fingerprint);

        $grown = $inode === $newInode && $firstLine === $newFirstLine && (int) $newSize > (int) $size;

        return $grown && $this->endsWithKnownMessage($entries, (int) $size) ? $entries : null;
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
        // A message appended while scanning belongs to the next run, so it is not indexed twice.
        foreach ($this->mailbox->messages($fromOffset, $untilOffset) as [$span, $message, $received]) {
            $entries[] = $this->parser->summarize($message, $span, $received);
        }

        return $entries;
    }

    /**
     * @return list<mixed>
     */
    private static function toRow(MessageIndexEntry $entry): array
    {
        return [
            $entry->span->offset,
            $entry->span->length,
            $entry->id,
            self::dateToRow($entry->date),
            $entry->sender,
            $entry->fromName,
            $entry->recipients,
            $entry->recipientLabels,
            $entry->recipientAddresses,
            $entry->subject,
            $entry->attachmentCount,
            self::dateToRow($entry->received),
            $entry->messageId,
            array_map(static fn (Address $address): array => [$address->address, $address->name], $entry->to),
            array_map(static fn (Address $address): array => [$address->address, $address->name], $entry->cc),
        ];
    }

    /**
     * @param list<mixed> $rows
     *
     * @return list<MessageIndexEntry>|null null when a row does not fit, so the index is built again
     */
    private static function fromRows(array $rows): ?array
    {
        $entries = [];
        try {
            foreach ($rows as $row) {
                if (!is_array($row) || 15 !== count($row)) {
                    return null;
                }
                $entries[] = new MessageIndexEntry(
                    new MessageSpan($row[0], $row[1]),
                    $row[2],
                    self::dateFromRow($row[3]),
                    $row[4],
                    $row[5],
                    $row[6],
                    $row[7],
                    $row[8],
                    $row[9],
                    $row[10],
                    self::dateFromRow($row[11]),
                    $row[12],
                    self::addressesFromRow($row[13]),
                    self::addressesFromRow($row[14]),
                );
            }
        } catch (Throwable) {
            return null;
        }

        return $entries;
    }

    /**
     * @return array{string, string}|null local time and zone name, which keeps offsets and named zones apart
     */
    private static function dateToRow(?DateTimeImmutable $date): ?array
    {
        return null === $date ? null : [$date->format('Y-m-d H:i:s.u'), $date->getTimezone()->getName()];
    }

    private static function dateFromRow(mixed $row): ?DateTimeImmutable
    {
        if (null === $row) {
            return null;
        }
        if (!is_array($row) || !is_string($row[0] ?? null) || !is_string($row[1] ?? null)) {
            throw new UnexpectedValueException('Invalid date in the mail index.', 4180227591);
        }

        return new DateTimeImmutable($row[0], new DateTimeZone($row[1]));
    }

    /**
     * @return list<Address>
     */
    private static function addressesFromRow(mixed $row): array
    {
        if (!is_array($row)) {
            throw new UnexpectedValueException('Invalid addresses in the mail index.', 9310776254);
        }

        return array_values(array_map(static fn (mixed $pair): Address => is_array($pair) ? new Address($pair[0], $pair[1]) : throw new UnexpectedValueException('Invalid address in the mail index.', 2093317548), $row));
    }

    private function cacheKey(): string
    {
        return self::CACHE_VERSION.hash('sha256', $this->mailbox->path());
    }

    private static function sizeOf(string $fingerprint): int
    {
        return (int) explode('|', $fingerprint)[0];
    }
}
