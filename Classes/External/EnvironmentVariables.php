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

namespace KonradMichalik\Typo3Letterbox\External;

/**
 * EnvironmentVariables.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class EnvironmentVariables
{
    /**
     * @param array<string, string>|null $values fixed values instead of the process environment, for tests
     */
    public function __construct(
        private ?array $values = null,
    ) {}

    public function get(string $name): string
    {
        $value = null === $this->values ? getenv($name) : ($this->values[$name] ?? false);

        return false === $value ? '' : trim($value);
    }
}
