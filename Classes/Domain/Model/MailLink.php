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
 * MailLink.
 *
 * A link found in a mail. A password reset link is flagged so the Links tab can point it out.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MailLink
{
    public function __construct(
        public string $url,
        public bool $isPasswordReset,
    ) {}
}
