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

use Stringable;

/**
 * Address.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class Address implements Stringable
{
    public function __construct(
        public string $address,
        public string $name = '',
    ) {}

    public function __toString(): string
    {
        return '' === $this->name ? $this->address : $this->name.' <'.$this->address.'>';
    }
}
