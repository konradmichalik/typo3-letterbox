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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\Command;

use KonradMichalik\Typo3Letterbox\Command\SendTestMailsCommand;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SendTestMailsCommandWithoutDebugModeTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SendTestMailsCommandWithoutDebugModeTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    #[Test]
    public function commandIsNotRegisteredWithoutDebugMode(): void
    {
        self::assertFalse($this->get(CommandRegistry::class)->has('letterbox:testmails:send'));
        self::assertFalse($this->getContainer()->has(SendTestMailsCommand::class));
    }
}
