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

namespace KonradMichalik\Typo3Letterbox\Configuration;

use TYPO3\CMS\Core\Configuration\Exception\{ExtensionConfigurationExtensionNotConfiguredException, ExtensionConfigurationPathDoesNotExistException};
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function is_numeric;
use function is_scalar;

/**
 * ExtensionSettings.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ExtensionSettings
{
    public function __construct(
        private ExtensionConfiguration $configuration,
    ) {}

    /**
     * @return mixed null when the setting is missing
     */
    public function raw(string $key): mixed
    {
        try {
            return $this->configuration->get('typo3_letterbox', $key);
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return null;
        }
    }

    public function get(string $key, string $default): string
    {
        $value = $this->raw($key);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->raw($key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
