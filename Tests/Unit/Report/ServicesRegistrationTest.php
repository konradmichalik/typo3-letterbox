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

namespace KonradMichalik\Typo3Letterbox\Tests\Unit\Report;

use KonradMichalik\Typo3Letterbox\Report\LetterboxStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\{ContainerBuilder, Definition};
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use TYPO3\CMS\Reports\Registry\StatusRegistry;

/**
 * ServicesRegistrationTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ServicesRegistrationTest extends TestCase
{
    #[Test]
    public function notRegisteredWithoutReports(): void
    {
        $container = $this->load(new ContainerBuilder());

        self::assertFalse($container->hasDefinition(LetterboxStatus::class));
    }

    #[Test]
    public function registeredWithTagWhenReportsIsPresent(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition(StatusRegistry::class, new Definition(StatusRegistry::class));

        $container = $this->load($builder);

        self::assertTrue($container->hasDefinition(LetterboxStatus::class));
        self::assertTrue($container->getDefinition(LetterboxStatus::class)->hasTag('reports.status'));
    }

    private function load(ContainerBuilder $builder): ContainerBuilder
    {
        (new PhpFileLoader($builder, new FileLocator(__DIR__.'/../../../Configuration')))->load('Services.php');

        return $builder;
    }
}
