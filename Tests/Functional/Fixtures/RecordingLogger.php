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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

use function is_scalar;

/**
 * RecordingLogger.
 *
 * Keeps every entry as "<level> <message>" with the context placeholders filled in.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{'.$key.'}'] = is_scalar($value) || $value instanceof Stringable ? (string) $value : get_debug_type($value);
        }
        $this->lines[] = (is_scalar($level) ? (string) $level : '').' '.strtr((string) $message, $replacements);
    }
}
