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

use KonradMichalik\Typo3Letterbox\External\Api\ApiMiddleware;

// Before site resolution, so the API works without a site for the host and never reaches a page cache.
return [
    'frontend' => [
        'konradmichalik/typo3-letterbox/api' => [
            'target' => ApiMiddleware::class,
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
            'before' => [
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
