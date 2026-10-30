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

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// The v14 module menu draws monochrome icons in currentColor; v13 expects the coloured extension tile.
$moduleIcon = GeneralUtility::makeInstance(Typo3Version::class)->getMajorVersion() >= 14
    ? 'module-letterbox.svg'
    : 'Extension.svg';

return [
    // The toolbar draws the monochrome glyph in every version, unlike the module menu of TYPO3 13.
    'letterbox-glyph' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_letterbox/Resources/Public/Icons/module-letterbox.svg',
    ],
    'letterbox-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_letterbox/Resources/Public/Icons/'.$moduleIcon,
    ],
];
