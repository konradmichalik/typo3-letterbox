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
     * @param list<string> $recipients
     */
    public function __construct(
        public MessageSpan $span,
        public string $id,
        public ?DateTimeImmutable $date,
        public string $sender,
        public array $recipients,
        public string $subject,
    ) {}
}
