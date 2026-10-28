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
use KonradMichalik\Typo3Letterbox\External\Tester\TesterInboxMiddleware;

// Before site resolution, so the API and the tester inbox work without a site for the host and never reach a page cache.
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
        'konradmichalik/typo3-letterbox/tester-inbox' => [
            'target' => TesterInboxMiddleware::class,
            'after' => [
                'konradmichalik/typo3-letterbox/api',
            ],
            'before' => [
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
