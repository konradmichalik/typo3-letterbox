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

return [
    'letterbox-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_letterbox/Resources/Public/Icons/Extension.svg',
    ],
];
