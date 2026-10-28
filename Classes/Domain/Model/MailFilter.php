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

namespace KonradMichalik\Typo3Letterbox\Domain\Model;

use KonradMichalik\Typo3Letterbox\Mbox\MessageIndexEntry;

use function in_array;
use function strtolower;

/**
 * MailFilter.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MailFilter
{
    /**
     * @param array<string, int> $readLookup read ids as keys, so the unread check is a hash lookup
     */
    private function __construct(
        private string $query,
        private QuickFilter $quick,
        private array $readLookup,
        private string $recipient = '',
        private string $subject = '',
    ) {}

    public static function all(): self
    {
        return new self('', QuickFilter::All, []);
    }

    /**
     * @param string       $query   already trimmed and capped, as InboxQuery reads it from the request
     * @param list<string> $readIds
     */
    public static function create(string $query, QuickFilter $quick, array $readIds): self
    {
        return new self($query, $quick, array_flip($readIds));
    }

    public function matches(MessageIndexEntry $entry): bool
    {
        if (!$this->recipientAndSubjectMatch($entry)) {
            return false;
        }
        if (QuickFilter::Unread === $this->quick && isset($this->readLookup[$entry->id])) {
            return false;
        }
        if (QuickFilter::Attachments === $this->quick && $entry->attachmentCount < 1) {
            return false;
        }

        return '' === $this->query || $this->queryMatches($entry);
    }

    public function query(): string
    {
        return $this->query;
    }

    public function quick(): QuickFilter
    {
        return $this->quick;
    }

    public function withQuick(QuickFilter $quick): self
    {
        return new self($this->query, $quick, $this->readLookup, $this->recipient, $this->subject);
    }

    public function withRecipient(string $address): self
    {
        return new self($this->query, $this->quick, $this->readLookup, strtolower(trim($address)), $this->subject);
    }

    public function withSubject(string $needle): self
    {
        return new self($this->query, $this->quick, $this->readLookup, $this->recipient, $needle);
    }

    private function recipientAndSubjectMatch(MessageIndexEntry $entry): bool
    {
        if ('' !== $this->recipient && !in_array($this->recipient, $entry->recipientAddresses, true)) {
            return false;
        }

        return '' === $this->subject || false !== mb_stripos($entry->subject, $this->subject);
    }

    private function queryMatches(MessageIndexEntry $entry): bool
    {
        foreach ([$entry->subject, $entry->sender, $entry->fromName, ...$entry->recipients, ...$entry->recipientLabels] as $haystack) {
            if (false !== mb_stripos($haystack, $this->query)) {
                return true;
            }
        }

        return false;
    }
}
