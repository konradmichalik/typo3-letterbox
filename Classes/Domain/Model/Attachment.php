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
 * Attachment.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class Attachment
{
    public function __construct(
        public int $index,
        public string $filename,
        public string $contentId,
        public string $mimeType,
        public int $size,
        public string $content,
    ) {}
}
