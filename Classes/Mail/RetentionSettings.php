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

use TYPO3\CMS\Core\Configuration\Exception\{ExtensionConfigurationExtensionNotConfiguredException, ExtensionConfigurationPathDoesNotExistException};
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function is_numeric;

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

    public static function fromExtensionConfiguration(ExtensionConfiguration $configuration): self
    {
        return new self(
            self::limit($configuration, 'maxMessages', self::DEFAULT_MAX_MESSAGES),
            self::limit($configuration, 'maxAgeDays', self::DEFAULT_MAX_AGE_DAYS),
        );
    }

    public function isActive(): bool
    {
        return $this->maxMessages > 0 || $this->maxAgeDays > 0;
    }

    private static function limit(ExtensionConfiguration $configuration, string $name, int $default): int
    {
        try {
            $value = $configuration->get('typo3_letterbox', $name);
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return $default;
        }

        return is_numeric($value) ? max(0, (int) $value) : $default;
    }
}
