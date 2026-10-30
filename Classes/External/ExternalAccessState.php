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
 * ExternalAccessState.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ExternalAccessState
{
    public const SETTING_OFF = 'setting_off';
    public const ENV_MISSING = 'env_missing';
    public const ENV_CONTEXT_MISMATCH = 'env_context_mismatch';
    public const PRODUCTION = 'production';
    public const TRANSPORT_NOT_LETTERBOX = 'transport_not_letterbox';
    public const TOKEN_MISSING = 'token_missing';
    public const HTTPS_REQUIRED = 'https_required';

    /** Active only without a single reason against it, so no state can be active and refused at once. */
    public bool $active;

    /**
     * @param list<string> $reasons machine keys, see the constants
     */
    public function __construct(
        public array $reasons,
    ) {
        $this->active = [] === $reasons;
    }

    public function withReason(string $reason): self
    {
        return new self([...$this->reasons, $reason]);
    }
}
