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

namespace KonradMichalik\Typo3Letterbox\Tests\Unit\Configuration;

use KonradMichalik\Typo3Letterbox\Configuration\ExtensionSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;
use TYPO3\CMS\Core\Configuration\Exception\{ExtensionConfigurationExtensionNotConfiguredException, ExtensionConfigurationPathDoesNotExistException};
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * ExtensionSettingsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ExtensionSettingsTest extends TestCase
{
    #[Test]
    public function readsTheSettingOfThisExtension(): void
    {
        $configuration = $this->createMock(ExtensionConfiguration::class);
        $configuration->expects(self::once())->method('get')->with('typo3_letterbox', 'mailsPerPage')->willReturn('25');

        self::assertSame('25', (new ExtensionSettings($configuration))->get('mailsPerPage', '10'));
    }

    #[Test]
    public function missingPathFallsBackToTheDefault(): void
    {
        $settings = $this->throwing(new ExtensionConfigurationPathDoesNotExistException('missing', 1));

        self::assertSame('1', $settings->get('showToolbarItem', '1'));
        self::assertSame(7, $settings->int('maxAgeDays', 7));
        self::assertNull($settings->raw('externalExcludedRecipients'));
    }

    #[Test]
    public function unconfiguredExtensionFallsBackToTheDefault(): void
    {
        self::assertSame('0', $this->throwing(new ExtensionConfigurationExtensionNotConfiguredException('missing', 1))->get('debugMode', '0'));
    }

    #[Test]
    public function nonScalarValueFallsBackToTheDefault(): void
    {
        $settings = $this->returning(['nested' => 'value']);

        self::assertSame('1', $settings->get('enableBackendModule', '1'));
        self::assertSame(['nested' => 'value'], $settings->raw('enableBackendModule'));
    }

    #[Test]
    public function scalarValuesAreReturnedAsString(): void
    {
        self::assertSame('1', $this->returning(true)->get('debugMode', '0'));
        self::assertSame('12', $this->returning(12)->get('mailsPerPage', '10'));
    }

    #[Test]
    public function intReadsNumericValuesAndFallsBackOtherwise(): void
    {
        self::assertSame(-3, $this->returning('-3')->int('maxAgeDays', 30));
        self::assertSame(30, $this->returning('many')->int('maxAgeDays', 30));
        self::assertSame(30, $this->returning('')->int('maxAgeDays', 30));
    }

    private function returning(mixed $value): ExtensionSettings
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn($value);

        return new ExtensionSettings($configuration);
    }

    private function throwing(Throwable $exception): ExtensionSettings
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willThrowException($exception);

        return new ExtensionSettings($configuration);
    }
}
