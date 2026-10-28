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

use Composer\Autoload;
use ShipMonk\ComposerDependencyAnalyser;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$rootPath = dirname(__DIR__, 2);

/** @var Autoload\ClassLoader $loader */
$loader = require $rootPath.'/vendor/autoload.php';
$loader->register();

$configuration = new ComposerDependencyAnalyser\Config\Configuration();
$configuration
    ->addPathsToScan([
        $rootPath.'/Classes',
        $rootPath.'/Configuration',
    ], false)
    ->addPathsToScan([
        $rootPath.'/Tests/Unit',
        $rootPath.'/Tests/Functional',
    ], true)
    ->addPathsToExclude([
        $rootPath.'/Tests/CGL',
    ])
    // Provides the frontend middleware stack, which Configuration/RequestMiddlewares.php references by identifier only.
    ->ignoreErrorsOnPackage('typo3/cms-frontend', [ErrorType::UNUSED_DEPENDENCY])
    // Optional (composer suggest). The status provider is only registered when it is installed.
    ->ignoreErrorsOnPackage('typo3/cms-reports', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    // Optional (composer suggest). Configuration/Services.php registers the widgets only behind hasDefinition(WidgetRegistry::class).
    ->ignoreErrorsOnPackage('typo3/cms-dashboard', [ErrorType::DEV_DEPENDENCY_IN_PROD])
;

return $configuration;
