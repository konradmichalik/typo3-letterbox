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

use ComposerUnused\ComposerUnused\Configuration\Configuration;

return static function (Configuration $config): Configuration {
    // Runtime-only dependencies (used via configuration files, not PHP symbols)
    // go here, each with a comment explaining why:
    // ->addNamedFilter(NamedFilter::fromString('typo3/cms-frontend'))
    return $config;
};
