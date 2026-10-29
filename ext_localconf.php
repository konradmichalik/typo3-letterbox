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

use KonradMichalik\Typo3Letterbox\External\EnvironmentVariables;
use KonradMichalik\Typo3Letterbox\Mail\TransportTakeover;
use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Core\Environment;

defined('TYPO3') || exit;

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['typo3_letterbox_index'] ??= [
    'frontend' => VariableFrontend::class,
    'backend' => SimpleFileBackend::class,
    'groups' => ['system'],
];

// Runs after settings.php and additional.php and before any mailer is built, see TransportTakeover.
$letterboxSource = TransportTakeover::source(
    (new EnvironmentVariables())->get(TransportTakeover::ENVIRONMENT_VARIABLE),
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['enable'] ?? '0',
);
$GLOBALS['TYPO3_CONF_VARS']['MAIL'] = TransportTakeover::apply(
    (array) ($GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? []),
    null !== $letterboxSource,
    $letterboxSource ?? TransportTakeover::BY_SETTING,
    Environment::getVarPath(),
);
unset($letterboxSource);
