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

/**
 * MailFilter.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MailFilter
{
    public static function all(): self
    {
        return new self();
    }

    public function matches(MessageIndexEntry $entry): bool
    {
        return true;
    }
}
