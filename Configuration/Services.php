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

use KonradMichalik\Typo3Letterbox\Command\{ClearCommand, ListCommand, ReceiveCommand, SendTestMailsCommand, ShowCommand};
use KonradMichalik\Typo3Letterbox\Report\LetterboxStatus;
use KonradMichalik\Typo3Letterbox\Widgets\{AdminOnlyRecentMailsWidget, RecentMailsWidget};
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Dashboard\WidgetRegistry;
use TYPO3\CMS\Dashboard\Widgets\AdminOnlyWidgetInterface;
use TYPO3\CMS\Reports\Registry\StatusRegistry;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder): void {
    $services = $container->services();

    // TYPO3 13 has no #[AsNonSchedulableCommand], so these commands are tagged by hand instead of autoconfigured.
    $commands = [
        ListCommand::class => ['letterbox:list', 'List the caught mails, newest first'],
        ShowCommand::class => ['letterbox:show', 'Print one part of a caught mail'],
        ClearCommand::class => ['letterbox:clear', 'Delete every caught mail'],
        ReceiveCommand::class => [ReceiveCommand::NAME, 'Store one mail from stdin, for use as sendmail_path'],
    ];
    foreach ($commands as $class => [$name, $description]) {
        $services->set($class)->autowire()->tag('console.command', ['command' => $name, 'description' => $description, 'schedulable' => false]);
    }

    // EXT:reports is a suggestion. Core loads it before this extension (composer suggest), so its registry is known here.
    if ($containerBuilder->hasDefinition(StatusRegistry::class)) {
        $services->set(LetterboxStatus::class)->autowire()->tag('reports.status');
    }

    // EXT:dashboard is a suggestion too. The admin-only marker interface exists in TYPO3 14 only.
    if ($containerBuilder->hasDefinition(WidgetRegistry::class)) {
        $services->set('dashboard.widget.letterboxRecentMails', interface_exists(AdminOnlyWidgetInterface::class) ? AdminOnlyRecentMailsWidget::class : RecentMailsWidget::class)
            ->autowire()
            ->tag('dashboard.widget', [
                'identifier' => 'letterboxRecentMails',
                'groupNames' => 'systemInfo',
                'title' => 'LLL:EXT:typo3_letterbox/Resources/Private/Language/locallang.xlf:widget.title',
                'description' => 'LLL:EXT:typo3_letterbox/Resources/Private/Language/locallang.xlf:widget.description',
                'iconIdentifier' => 'letterbox-module',
                'height' => 'medium',
                'width' => 'medium',
            ]);
    }

    if ('1' !== (string) ($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['debugMode'] ?? '0')) {
        return;
    }

    $services->set(SendTestMailsCommand::class)
        ->autowire()
        ->tag('console.command', ['command' => 'letterbox:testmails:send', 'schedulable' => false]);
};
