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

/**
 * MailFilter.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MailFilter
{
    private const MAX_QUERY_LENGTH = 200;

    /**
     * @param list<string> $readIds
     */
    private function __construct(
        private string $query,
        private QuickFilter $quick,
        private array $readIds,
    ) {}

    public static function all(): self
    {
        return new self('', QuickFilter::All, []);
    }

    /**
     * @param list<string> $readIds
     */
    public static function create(string $query, QuickFilter $quick, array $readIds): self
    {
        return new self(mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH), $quick, $readIds);
    }

    public function matches(MessageIndexEntry $entry): bool
    {
        if (QuickFilter::Unread === $this->quick && in_array($entry->id, $this->readIds, true)) {
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
        return new self($this->query, $quick, $this->readIds);
    }

    private function queryMatches(MessageIndexEntry $entry): bool
    {
        foreach ([$entry->subject, $entry->sender, $entry->fromName, ...$entry->recipients] as $haystack) {
            if (false !== mb_stripos($haystack, $this->query)) {
                return true;
            }
        }

        return false;
    }
}
