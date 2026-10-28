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

namespace KonradMichalik\Typo3Letterbox\Tests\Unit\Mail;

use KonradMichalik\Typo3Letterbox\Mail\RetentionSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * RetentionSettingsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RetentionSettingsTest extends TestCase
{
    #[Test]
    public function missingSettingsFallBackToDefaults(): void
    {
        $configuration = $this->createMock(ExtensionConfiguration::class);
        $configuration->method('get')->willThrowException(new ExtensionConfigurationPathDoesNotExistException('missing', 1));

        $settings = RetentionSettings::fromExtensionConfiguration($configuration);

        self::assertSame(500, $settings->maxMessages);
        self::assertSame(30, $settings->maxAgeDays);
        self::assertTrue($settings->isActive());
    }

    #[Test]
    public function configuredValuesWinAndNegativeValuesDisableTheLimit(): void
    {
        $settings = RetentionSettings::fromExtensionConfiguration($this->configuration(['maxMessages' => '20', 'maxAgeDays' => '-3']));

        self::assertSame(20, $settings->maxMessages);
        self::assertSame(0, $settings->maxAgeDays);
        self::assertTrue($settings->isActive());
    }

    #[Test]
    public function bothLimitsZeroIsInactive(): void
    {
        self::assertFalse(RetentionSettings::fromExtensionConfiguration($this->configuration(['maxMessages' => '0', 'maxAgeDays' => '0']))->isActive());
    }

    /**
     * @param array<string, string> $values
     */
    private function configuration(array $values): ExtensionConfiguration
    {
        $configuration = $this->createMock(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(static fn (string $extension, string $path): string => $values[$path]);

        return $configuration;
    }
}
