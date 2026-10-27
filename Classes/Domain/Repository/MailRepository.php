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

use KonradMichalik\Typo3Letterbox\Domain\Model\{Attachment, Mail, MailFilter};
use KonradMichalik\Typo3Letterbox\Mbox\{MailIndex, Mailbox, MailboxWriter, MessageIndexEntry, MessageParser};
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

    public function findById(string $id, MailFilter $filter): ?Mail
    {
        foreach ($this->matching($filter) as $entry) {
            if ($entry->id === $id) {
                return $this->load($entry);
            }
        }

        return null;
    }

    public function attachment(string $id, int $index, MailFilter $filter): ?Attachment
    {
        $mail = $this->findById($id, $filter);
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
                return $this->writer->remove([$entry->span], fn (): bool => $this->isCurrent($entry));
            }
        }

        return false;
    }

    public function clear(): void
    {
        $this->writer->truncate();
    }

    public function isConfigured(): bool
    {
        return '' !== $this->mailbox->path();
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
