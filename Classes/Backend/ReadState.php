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

namespace KonradMichalik\Typo3Letterbox\Backend;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

use function array_slice;
use function in_array;
use function is_array;
use function is_string;

/**
 * ReadState.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ReadState
{
    private const MAX_IDS = 2000;

    /**
     * @return list<string>
     */
    public function readIds(): array
    {
        $stored = $this->backendUser()->uc['letterbox']['read'] ?? [];

        return is_array($stored) ? array_values(array_filter($stored, is_string(...))) : [];
    }

    public function isRead(string $id): bool
    {
        return in_array($id, $this->readIds(), true);
    }

    public function markRead(string $id): void
    {
        $ids = array_values(array_filter($this->readIds(), static fn (string $known): bool => $known !== $id));
        $ids[] = $id;

        $backendUser = $this->backendUser();
        $backendUser->uc['letterbox']['read'] = array_slice($ids, -self::MAX_IDS);
        $backendUser->writeUC();
    }

    private function backendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
