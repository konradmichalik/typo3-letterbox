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

use KonradMichalik\Typo3Letterbox\Command\SendTestMailsCommand;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder): void {
    if ('1' !== (string) ($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['debugMode'] ?? '0')) {
        return;
    }

    $container->services()->set(SendTestMailsCommand::class)
        ->autowire()
        ->tag('console.command', ['command' => 'letterbox:testmails:send', 'schedulable' => false]);
};
