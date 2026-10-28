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
        public bool $unrestricted,
        public bool $canDownload,
        public bool $canDelete,
        public int $expires,
        public int $created,
        public int $createdBy,
        public int $lastUsed,
        public int $revoked,
        public bool $scopeValid = true,
    ) {}

    public function isUsable(int $now): bool
    {
        if (!$this->scopeValid || 0 !== $this->revoked || $now >= $this->expires) {
            return false;
        }

        return $this->unrestricted ? [] === $this->scope : [] !== $this->scope;
    }

    /**
     * Adds the link scope on top of the base filter, so the link never sees more than the base filter allows.
     */
    public function filter(MailFilter $base): MailFilter
    {
        return $this->unrestricted && $this->scopeValid ? $base : $base->withAdditionalScope($this->scope);
    }
}
