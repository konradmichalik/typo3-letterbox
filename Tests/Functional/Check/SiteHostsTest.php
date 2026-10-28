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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\Check;

use KonradMichalik\Typo3Letterbox\Check\SiteHosts;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SiteHostsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SiteHostsTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['typo3_letterbox'];

    #[Test]
    public function listsLowercaseHostsOfAllSites(): void
    {
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://Example.org/');

        self::assertSame(['example.org'], $this->get(SiteHosts::class)->all());
    }

    #[Test]
    public function noSitesYieldNoHosts(): void
    {
        self::assertSame([], $this->get(SiteHosts::class)->all());
    }
}
