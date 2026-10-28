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

use KonradMichalik\Typo3Letterbox\Command\{ClearCommand, ListCommand, SendTestMailsCommand, ShowCommand};
use KonradMichalik\Typo3Letterbox\Report\LetterboxStatus;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Reports\Registry\StatusRegistry;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder): void {
    $services = $container->services();

    // TYPO3 13 has no #[AsNonSchedulableCommand], so these commands are tagged by hand instead of autoconfigured.
    $commands = [
        ListCommand::class => ['letterbox:list', 'List the caught mails, newest first'],
        ShowCommand::class => ['letterbox:show', 'Print one part of a caught mail'],
        ClearCommand::class => ['letterbox:clear', 'Delete every caught mail'],
    ];
    foreach ($commands as $class => [$name, $description]) {
        $services->set($class)->autowire()->tag('console.command', ['command' => $name, 'description' => $description, 'schedulable' => false]);
    }

    // EXT:reports is a suggestion. Core loads it before this extension (composer suggest), so its registry is known here.
    if ($containerBuilder->hasDefinition(StatusRegistry::class)) {
        $services->set(LetterboxStatus::class)->autowire()->tag('reports.status');
    }

    if ('1' !== (string) ($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['debugMode'] ?? '0')) {
        return;
    }

    $services->set(SendTestMailsCommand::class)
        ->autowire()
        ->tag('console.command', ['command' => 'letterbox:testmails:send', 'schedulable' => false]);
};
