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

namespace KonradMichalik\Typo3Letterbox\Domain\Repository;

use DateInterval;
use DateTimeImmutable;
use KonradMichalik\Typo3Letterbox\Domain\Model\{Attachment, Mail, MailFilter, MailLink, QuickFilter};
use KonradMichalik\Typo3Letterbox\Mbox\{MailIndex, Mailbox, MailboxWriter, MessageIndexEntry, MessageParser, MessageSpan};
use RuntimeException;

use function array_slice;
use function count;

/**
 * MailRepository.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MailRepository
{
    public function __construct(
        private MailIndex $index,
        private Mailbox $mailbox,
        private MessageParser $parser,
        private MailboxWriter $writer,
    ) {}

    public function count(MailFilter $filter): int
    {
        return count($this->matching($filter));
    }

    /**
     * Counts the mails each quick filter would show with the filter's search query, in one pass over the index.
     *
     * @return array<value-of<QuickFilter>, int>
     */
    public function quickFilterCounts(MailFilter $filter): array
    {
        $filters = [];
        $counts = [];
        foreach (QuickFilter::cases() as $quick) {
            $filters[$quick->value] = $filter->withQuick($quick);
            $counts[$quick->value] = 0;
        }

        foreach ($this->index->entries() as $entry) {
            // "All" applies only the query, so an entry it rejects matches no other quick filter either.
            if (!$filters[QuickFilter::All->value]->matches($entry)) {
                continue;
            }
            foreach ($filters as $value => $quickFilter) {
                if ($quickFilter->matches($entry)) {
                    ++$counts[$value];
                }
            }
        }

        return $counts;
    }

    /**
     * @return list<Mail>
     */
    public function find(MailFilter $filter, int $offset, int $limit): array
    {
        $mails = [];
        foreach (array_slice($this->matching($filter), max(0, $offset), max(0, $limit)) as $entry) {
            $mail = $this->load($entry);
            if (null !== $mail) {
                $mails[] = $mail;
            }
        }

        return $mails;
    }

    /**
     * @return list<MessageIndexEntry> newest first
     */
    public function findEntries(MailFilter $filter, int $offset, int $limit): array
    {
        return array_slice($this->matching($filter), max(0, $offset), max(0, $limit));
    }

    /**
     * The links of one mail, read from its text and HTML part. Empty when the file changed after the index was read.
     *
     * @return list<MailLink>
     */
    public function links(MessageIndexEntry $entry): array
    {
        try {
            $raw = $this->mailbox->read($entry->span);
        } catch (RuntimeException) {
            return [];
        }

        return MessageParser::idOf($raw) === $entry->id ? $this->parser->links($raw) : [];
    }

    public function findById(string $id): ?Mail
    {
        foreach ($this->index->entries() as $entry) {
            if ($entry->id === $id) {
                return $this->load($entry);
            }
        }

        return null;
    }

    public function attachment(string $id, int $index): ?Attachment
    {
        $mail = $this->findById($id);
        if (null === $mail) {
            return null;
        }

        foreach ($mail->attachments as $attachment) {
            if ($attachment->index === $index) {
                return $attachment;
            }
        }

        return null;
    }

    public function delete(string $id): bool
    {
        foreach ($this->index->entries() as $entry) {
            if ($entry->id === $id) {
                return $this->remove([$entry]) > 0;
            }
        }

        return false;
    }

    /**
     * Removes the oldest mails beyond $maxMessages and every mail that arrived more than $maxAgeDays ago.
     * A limit of 0 is off. Mails without a readable arrival time count for $maxMessages but never expire.
     *
     * @return int the number of removed mails
     */
    public function prune(int $maxMessages, int $maxAgeDays, DateTimeImmutable $now): int
    {
        return $this->remove($this->expired($maxMessages, $maxAgeDays, $now));
    }

    /**
     * Decides from the index alone, without reading a mail.
     */
    public function needsPruning(int $maxMessages, int $maxAgeDays, DateTimeImmutable $now): bool
    {
        return [] !== $this->expired($maxMessages, $maxAgeDays, $now);
    }

    public function latest(MailFilter $filter): ?Mail
    {
        return $this->find($filter, 0, 1)[0] ?? null;
    }

    public function clear(): void
    {
        $this->writer->truncate();
    }

    public function isConfigured(): bool
    {
        return '' !== $this->mailbox->path();
    }

    /**
     * A missing file is an empty mailbox. Anything else at the path has to be a readable file.
     */
    public function isReadable(): bool
    {
        $path = $this->mailbox->path();

        return !file_exists($path) || (is_file($path) && is_readable($path));
    }

    public function path(): string
    {
        return $this->mailbox->path();
    }

    public function sizeInBytes(): int
    {
        return $this->mailbox->exists() ? (int) filesize($this->mailbox->path()) : 0;
    }

    /**
     * @return list<MessageIndexEntry>
     */
    private function matching(MailFilter $filter): array
    {
        return array_values(array_filter($this->index->entries(), $filter->matches(...)));
    }

    /**
     * @return list<MessageIndexEntry>
     */
    private function expired(int $maxMessages, int $maxAgeDays, DateTimeImmutable $now): array
    {
        $cutoff = $maxAgeDays > 0 ? $now->sub(new DateInterval('P'.$maxAgeDays.'D')) : null;
        $expired = [];
        foreach ($this->index->entries() as $position => $entry) {
            $beyondCount = $maxMessages > 0 && $position >= $maxMessages;
            $tooOld = null !== $cutoff && null !== $entry->received && $entry->received < $cutoff;
            if ($beyondCount || $tooOld) {
                $expired[] = $entry;
            }
        }

        return $expired;
    }

    /**
     * Removes all entries in one rewrite, or none when the file changed since the index was read.
     * Mails appended after that stay, because only the given spans are cut out. The index follows without a scan.
     *
     * @param list<MessageIndexEntry> $entries
     */
    private function remove(array $entries): int
    {
        $spans = array_map(static fn (MessageIndexEntry $entry): MessageSpan => $entry->span, $entries);
        $removed = $this->writer->remove(
            $spans,
            function () use ($entries): bool {
                foreach ($entries as $entry) {
                    if (!$this->isCurrent($entry)) {
                        return false;
                    }
                }

                return true;
            },
            fn () => $this->index->removed($spans),
        );

        return $removed ? count($entries) : 0;
    }

    private function load(MessageIndexEntry $entry): ?Mail
    {
        try {
            $mail = $this->parser->parse($this->mailbox->read($entry->span));
        } catch (RuntimeException) {
            return null;
        }

        // The file changed after the index was read when the content at the span no longer matches.
        return $mail->id === $entry->id ? $mail : null;
    }

    private function isCurrent(MessageIndexEntry $entry): bool
    {
        try {
            return MessageParser::idOf($this->mailbox->read($entry->span)) === $entry->id;
        } catch (RuntimeException) {
            return false;
        }
    }
}
