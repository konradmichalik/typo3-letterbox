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

namespace KonradMichalik\Typo3Letterbox\Html;

use DOMElement;

use function in_array;

/**
 * LinkTargets.
 *
 * Makes the links of a mail open in a new tab without opener or referrer, and drops targets with a scheme
 * outside the allow list. Sandbox and policy already stop script URLs, this is the second line.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class LinkTargets
{
    // SVG links carry their target in href or the older xlink:href.
    private const ATTRIBUTES = ['href', 'xlink:href'];
    private const SCHEMES = ['http', 'https', 'mailto', 'tel', 'cid'];

    public static function secure(DOMElement $link): void
    {
        $hasTarget = false;
        foreach (self::ATTRIBUTES as $attribute) {
            if (!$link->hasAttribute($attribute)) {
                continue;
            }
            if (self::isAllowed($link->getAttribute($attribute))) {
                $hasTarget = true;
            } else {
                $link->removeAttribute($attribute);
            }
        }
        if ($hasTarget) {
            $link->setAttribute('target', '_blank');
            $link->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * Browsers drop whitespace and control characters inside a scheme, so the check does too.
     */
    private static function isAllowed(string $target): bool
    {
        $normalized = strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', $target));

        return 1 !== preg_match('/^([a-z][a-z0-9+.-]*):/', $normalized, $match) || in_array($match[1], self::SCHEMES, true);
    }
}
