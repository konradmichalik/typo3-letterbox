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

/**
 * QuickFilter.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
enum QuickFilter: string
{
    case All = 'all';
    case Unread = 'unread';
    case Attachments = 'attachments';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::All;
    }
}
