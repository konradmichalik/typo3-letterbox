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

/**
 * MessageIndexEntry.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MessageIndexEntry
{
    /**
     * @param list<string>       $recipients         lowercase addresses from To, Cc, Bcc and the envelope
     * @param list<string>       $recipientLabels    per recipient, its display name or else its address
     * @param list<string>       $recipientAddresses lowercase addresses from To, Cc and the envelope, never from the Bcc header
     * @param ?DateTimeImmutable $received           arrival time from the separator line, unlike $date not set by the sender
     */
    public function __construct(
        public MessageSpan $span,
        public string $id,
        public ?DateTimeImmutable $date,
        public string $sender,
        public string $fromName,
        public array $recipients,
        public array $recipientLabels,
        public array $recipientAddresses,
        public string $subject,
        public int $attachmentCount,
        public ?DateTimeImmutable $received = null,
    ) {}
}
