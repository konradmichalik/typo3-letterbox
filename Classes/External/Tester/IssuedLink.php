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

namespace KonradMichalik\Typo3Letterbox\External\Tester;

/**
 * IssuedLink.
 *
 * A freshly issued tester link together with its plain token, which exists only in this object and is never stored.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class IssuedLink
{
    public function __construct(
        public TesterLink $link,
        public string $token,
    ) {}
}
