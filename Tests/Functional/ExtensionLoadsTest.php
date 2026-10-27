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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * ExtensionLoadsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ExtensionLoadsTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    public function testTypo3BootsWithExtensionLoaded(): void
    {
        self::assertTrue(ExtensionManagementUtility::isLoaded('typo3_letterbox'));
    }
}
