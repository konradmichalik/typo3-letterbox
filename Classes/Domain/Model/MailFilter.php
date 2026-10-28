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

use KonradMichalik\Typo3Letterbox\Mail\RecipientPattern;
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
     * @param array<string, int>           $readLookup       read ids as keys, so the unread check is a hash lookup
     * @param list<RecipientPattern>       $scope            at least one To, Cc or envelope recipient must match, empty means unrestricted
     * @param list<RecipientPattern>       $exclusions       no recipient, Bcc included, may match
     * @param list<list<RecipientPattern>> $additionalScopes every group must match on top of the scope, so they only ever narrow it
     */
    private function __construct(
        private string $query,
        private QuickFilter $quick,
        private array $readLookup,
        private array $scope = [],
        private array $exclusions = [],
        private string $recipient = '',
        private string $subject = '',
        private array $additionalScopes = [],
        private bool $scopedQuery = false,
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
        if (!$this->inScope($entry) || $this->isExcluded($entry) || !$this->recipientAndSubjectMatch($entry)) {
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

    /**
     * True when the filter matches every mail, so deleting what it matches may clear the whole file.
     */
    public function isUnrestricted(): bool
    {
        return '' === $this->query && QuickFilter::All === $this->quick && [] === $this->scope && [] === $this->additionalScopes && [] === $this->exclusions && '' === $this->recipient && '' === $this->subject;
    }

    /**
     * True when a scope restricts the recipients, so co-recipients outside it must stay hidden.
     */
    public function isScoped(): bool
    {
        return [] !== $this->scopeGroups();
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
        return new self($this->query, $quick, $this->readLookup, $this->scope, $this->exclusions, $this->recipient, $this->subject, $this->additionalScopes, $this->scopedQuery);
    }

    /**
     * @param list<RecipientPattern> $scope
     */
    public function withScope(array $scope): self
    {
        return new self($this->query, $this->quick, $this->readLookup, $scope, $this->exclusions, $this->recipient, $this->subject, $this->additionalScopes, $this->scopedQuery);
    }

    /**
     * Narrows the filter: the mail must match this scope in addition to the existing one, never instead of it.
     *
     * @param list<RecipientPattern> $scope
     */
    public function withAdditionalScope(array $scope): self
    {
        return new self($this->query, $this->quick, $this->readLookup, $this->scope, $this->exclusions, $this->recipient, $this->subject, [...$this->additionalScopes, $scope], $this->scopedQuery);
    }

    /**
     * @param list<RecipientPattern> $exclusions
     */
    public function withExclusions(array $exclusions): self
    {
        return new self($this->query, $this->quick, $this->readLookup, $this->scope, $exclusions, $this->recipient, $this->subject, $this->additionalScopes, $this->scopedQuery);
    }

    public function withRecipient(string $address): self
    {
        return new self($this->query, $this->quick, $this->readLookup, $this->scope, $this->exclusions, strtolower(trim($address)), $this->subject, $this->additionalScopes, $this->scopedQuery);
    }

    public function withSubject(string $needle): self
    {
        return new self($this->query, $this->quick, $this->readLookup, $this->scope, $this->exclusions, $this->recipient, $needle, $this->additionalScopes, $this->scopedQuery);
    }

    /**
     * A search that matches recipients only when they are in scope, so masked addresses and names cannot be probed.
     *
     * @param string $query already trimmed and capped
     */
    public function withScopedQuery(string $query): self
    {
        return new self($query, $this->quick, $this->readLookup, $this->scope, $this->exclusions, $this->recipient, $this->subject, $this->additionalScopes, true);
    }

    /**
     * The To and Cc addresses of a mail that fall into the scope, all of them when the scope is unrestricted.
     *
     * @return list<Address>
     */
    public function visibleRecipients(Mail $mail): array
    {
        $addresses = [...$mail->to, ...$mail->cc];
        $groups = $this->scopeGroups();
        if ([] === $groups) {
            return $addresses;
        }

        return array_values(array_filter($addresses, fn (Address $address): bool => $this->matchesEveryGroup($groups, [$address->address])));
    }

    private function inScope(MessageIndexEntry $entry): bool
    {
        $groups = $this->scopeGroups();
        if ([] === $groups) {
            return true;
        }

        foreach ($entry->scopeRecipients as $address) {
            if ($this->matchesEveryGroup($groups, [$address])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<list<RecipientPattern>>
     */
    private function scopeGroups(): array
    {
        return [] === $this->scope ? $this->additionalScopes : [$this->scope, ...$this->additionalScopes];
    }

    /**
     * @param list<list<RecipientPattern>> $groups
     * @param list<string>                 $addresses
     */
    private function matchesEveryGroup(array $groups, array $addresses): bool
    {
        foreach ($groups as $group) {
            if (!$this->anyMatches($group, $addresses)) {
                return false;
            }
        }

        return true;
    }

    private function isExcluded(MessageIndexEntry $entry): bool
    {
        return [] !== $this->exclusions && $this->anyMatches($this->exclusions, $entry->recipients);
    }

    private function recipientAndSubjectMatch(MessageIndexEntry $entry): bool
    {
        if ('' !== $this->recipient && !in_array($this->recipient, $entry->scopeRecipients, true)) {
            return false;
        }

        return '' === $this->subject || false !== mb_stripos($entry->subject, $this->subject);
    }

    /**
     * @param list<RecipientPattern> $patterns
     * @param list<string>           $addresses
     */
    private function anyMatches(array $patterns, array $addresses): bool
    {
        foreach ($addresses as $address) {
            foreach ($patterns as $pattern) {
                if ($pattern->matches($address)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function queryMatches(MessageIndexEntry $entry): bool
    {
        foreach ([$entry->subject, $entry->sender, $entry->fromName, ...$this->searchableRecipients($entry)] as $haystack) {
            if (false !== mb_stripos($haystack, $this->query)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function searchableRecipients(MessageIndexEntry $entry): array
    {
        $groups = $this->scopeGroups();
        if (!$this->scopedQuery || [] === $groups) {
            return [...$entry->recipients, ...$entry->recipientLabels];
        }

        return array_values(array_filter($entry->scopeRecipients, fn (string $address): bool => $this->matchesEveryGroup($groups, [$address])));
    }
}
