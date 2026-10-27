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

use DateTimeImmutable;


/**
 * Mail.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */

final readonly class Mail
{
    /**
     * @param list<Address>               $to
     * @param list<Address>               $cc
     * @param list<Address>               $bcc
     * @param list<Address>               $replyTo
     * @param list<Attachment>            $attachments
     * @param array<string, list<string>> $headers
     * @param list<string>                $links
     */
    public function __construct(
        public string $id,
        public string $messageId,
        public string $subject,
        public ?DateTimeImmutable $date,
        public ?Address $from,
        public array $to,
        public array $cc,
        public array $bcc,
        public array $replyTo,
        public string $text,
        public string $html,
        public array $attachments,
        public array $headers,
        public int $size,
        public string $source,
        public array $links,
    ) {}
}
