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

use KonradMichalik\Typo3Letterbox\Controller\{ExternalAccessController, InboxController};

if ('0' === (string) ($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['enableBackendModule'] ?? '1')) {
    return [];
}

return [
    'system_letterbox' => [
        'parent' => 'system',
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/system/letterbox',
        'iconIdentifier' => 'letterbox-module',
        'labels' => 'LLL:EXT:typo3_letterbox/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => InboxController::class.'::indexAction',
            ],
            'show' => [
                'target' => InboxController::class.'::showAction',
            ],
            'preview' => [
                'target' => InboxController::class.'::previewAction',
                'methods' => ['GET'],
            ],
            'source' => [
                'target' => InboxController::class.'::sourceAction',
            ],
            'attachment' => [
                'target' => InboxController::class.'::attachmentAction',
            ],
            'delete' => [
                'target' => InboxController::class.'::deleteAction',
                'methods' => ['POST'],
            ],
            'clear' => [
                'target' => InboxController::class.'::clearAction',
                'methods' => ['POST'],
            ],
            'markAllRead' => [
                'target' => InboxController::class.'::markAllReadAction',
                'methods' => ['POST'],
            ],
            'access' => [
                'target' => ExternalAccessController::class.'::indexAction',
                'methods' => ['GET'],
            ],
            'access_create' => [
                'target' => ExternalAccessController::class.'::createAction',
                'methods' => ['POST'],
            ],
            'access_revoke' => [
                'target' => ExternalAccessController::class.'::revokeAction',
                'methods' => ['POST'],
            ],
        ],
    ],
];
