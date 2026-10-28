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

namespace KonradMichalik\Typo3Letterbox\Mail;

use KonradMichalik\Typo3Letterbox\Configuration\ExtensionSettings;

/**
 * RetentionSettings.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RetentionSettings
{
    public const DEFAULT_MAX_MESSAGES = 500;
    public const DEFAULT_MAX_AGE_DAYS = 30;

    /**
     * @param int $maxMessages 0 keeps any number of mails
     * @param int $maxAgeDays  0 keeps mails of any age
     */
    public function __construct(
        public int $maxMessages,
        public int $maxAgeDays,
    ) {}

    public static function fromExtensionSettings(ExtensionSettings $settings): self
    {
        return new self(
            max(0, $settings->int('maxMessages', self::DEFAULT_MAX_MESSAGES)),
            max(0, $settings->int('maxAgeDays', self::DEFAULT_MAX_AGE_DAYS)),
        );
    }

    public function isActive(): bool
    {
        return $this->maxMessages > 0 || $this->maxAgeDays > 0;
    }
}
