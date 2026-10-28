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

use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Mail\RecipientPattern;

/**
 * TesterLink.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TesterLink
{
    /**
     * @param list<RecipientPattern> $scope
     * @param bool                   $scopeValid false when the stored scope could not be parsed, such a link is never usable
     */
    public function __construct(
        public int $uid,
        public string $label,
        public array $scope,
        public int $expires,
        public int $createdBy,
        public int $revoked,
        public bool $scopeValid = true,
    ) {}

    public function isUsable(int $now): bool
    {
        return $this->scopeValid && [] !== $this->scope && 0 === $this->revoked && $now < $this->expires;
    }

    /**
     * Adds the link scope on top of the base filter, so the link never sees more than the base filter allows.
     */
    public function filter(MailFilter $base): MailFilter
    {
        return $base->withAdditionalScope($this->scope);
    }
}
