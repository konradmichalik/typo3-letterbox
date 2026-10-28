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

use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;

/**
 * ExternalAccessState.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ExternalAccessState
{
    public const ENV_MISSING = 'env_missing';
    public const ENV_CONTEXT_MISMATCH = 'env_context_mismatch';
    public const TRANSPORT_NOT_LETTERBOX = 'transport_not_letterbox';
    public const MAIL_SETTINGS_INVALID = 'mail_settings_invalid';
    public const PRODUCTION_REQUIRES_LISTED = 'production_requires_listed';
    public const CATCH_LIST_EMPTY = 'catch_list_empty';
    public const EXCLUSIONS_INVALID = 'exclusions_invalid';
    public const IP_NOT_ALLOWED = 'ip_not_allowed';
    public const HTTPS_REQUIRED = 'https_required';

    /** Active only without a single reason against it, so no state can be active and refused at once. */
    public bool $active;

    /**
     * @param MailFilter   $baseFilter the exclusions, plus the catch list as scope in root Production
     * @param list<string> $reasons    machine keys, see the constants
     */
    public function __construct(
        public MailFilter $baseFilter,
        public array $reasons,
    ) {
        $this->active = [] === $reasons;
    }

    public function withReason(string $reason): self
    {
        return new self($this->baseFilter, [...$this->reasons, $reason]);
    }
}
