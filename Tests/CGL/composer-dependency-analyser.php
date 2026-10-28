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
    ->ignoreErrorsOnPackage('typo3/cms-core', [ErrorType::UNUSED_DEPENDENCY])
    // Required for the tester inbox cookie, which follows in a later phase 3 task.
    ->ignoreErrorsOnPackage('symfony/http-foundation', [ErrorType::UNUSED_DEPENDENCY])
    // Provides the frontend middleware stack, which Configuration/RequestMiddlewares.php references by identifier only.
    ->ignoreErrorsOnPackage('typo3/cms-frontend', [ErrorType::UNUSED_DEPENDENCY])
;

return $configuration;
