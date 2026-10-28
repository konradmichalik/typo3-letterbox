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

    /**
     * Opening an already read mail leaves uc untouched, so reading does not write on every open.
     */
    public function markRead(string $id): void
    {
        $ids = $this->readIds();
        if (in_array($id, $ids, true)) {
            return;
        }
        $ids[] = $id;

        $backendUser = $this->backendUser();
        $backendUser->uc['letterbox']['read'] = array_slice($ids, -self::MAX_IDS);
        $backendUser->writeUC();
    }

    /**
     * Adds all ids with one write of uc. The cap drops the oldest entries, so the ids come newest first,
     * as the repository lists mails, and are stored oldest first like markRead() does.
     *
     * @param list<string> $ids newest first
     */
    public function markAllRead(array $ids): void
    {
        $read = $this->readIds();
        $new = array_values(array_diff(array_reverse(array_unique($ids)), $read));
        if ([] === $new) {
            return;
        }

        $backendUser = $this->backendUser();
        $backendUser->uc['letterbox']['read'] = array_slice([...$read, ...$new], -self::MAX_IDS);
        $backendUser->writeUC();
    }

    /**
     * inbox.js writes the flag through core's persistent storage, where the key "letterbox.listCollapsed"
     * lands in uc['letterbox']['listCollapsed'] as a string. Reading it here paints the first frame correctly.
     */
    public function isListCollapsed(): bool
    {
        return filter_var($this->backendUser()->uc['letterbox']['listCollapsed'] ?? false, \FILTER_VALIDATE_BOOLEAN);
    }

    private function backendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
