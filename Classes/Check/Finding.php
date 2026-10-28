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
 * Finding.
 *
 * One result of a rule based check. The message is the label key below "check." in locallang.xlf,
 * rendered with the arguments, so the wording is translatable and plural forms stay correct.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class Finding
{
    /**
     * @param list<int|string> $arguments
     */
    public function __construct(
        public string $rule,
        public Severity $severity,
        public string $message,
        public string $excerpt = '',
        public array $arguments = [],
    ) {}
}
