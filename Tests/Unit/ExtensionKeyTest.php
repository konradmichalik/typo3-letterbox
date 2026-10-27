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

namespace KonradMichalik\Typo3Letterbox\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * ExtensionKeyTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ExtensionKeyTest extends TestCase
{
    public function testComposerJsonDeclaresExpectedExtensionKey(): void
    {
        $composerJsonPath = dirname(__DIR__, 2).'/composer.json';

        /** @var array{extra: array{"typo3/cms": array{"extension-key": string}}} $composerJson */
        $composerJson = json_decode((string) file_get_contents($composerJsonPath), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('typo3_letterbox', $composerJson['extra']['typo3/cms']['extension-key']);
    }
}
