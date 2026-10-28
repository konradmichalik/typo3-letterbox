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

namespace KonradMichalik\Typo3Letterbox\Check;

/**
 * Severity.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';

    public function rank(): int
    {
        return match ($this) {
            self::Error => 0,
            self::Warning => 1,
            self::Info => 2,
        };
    }
}
