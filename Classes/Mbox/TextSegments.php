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

namespace KonradMichalik\Typo3Letterbox\Mbox;

use function strlen;

/**
 * TextSegments.
 *
 * Splits a plain text part into plain and URL segments, so the view can link http(s) URLs with
 * default escaping instead of building HTML. It uses the same pattern as the link extraction.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TextSegments
{
    /**
     * @return list<array{text: string, url: string|null}>
     */
    public static function split(string $text): array
    {
        preg_match_all(MessageParser::URL_PATTERN, $text, $matches, \PREG_OFFSET_CAPTURE);

        $segments = [];
        $position = 0;
        foreach ($matches[0] as [$match, $offset]) {
            $url = rtrim($match, MessageParser::URL_TRAILING_PUNCTUATION);
            if ($offset > $position) {
                $segments[] = ['text' => substr($text, $position, $offset - $position), 'url' => null];
            }
            $segments[] = ['text' => $url, 'url' => $url];
            $position = $offset + strlen($url);
        }
        if ($position < strlen($text)) {
            $segments[] = ['text' => substr($text, $position), 'url' => null];
        }

        return $segments;
    }
}
