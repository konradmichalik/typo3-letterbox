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

namespace KonradMichalik\Typo3Letterbox\Widgets;

use TYPO3\CMS\Dashboard\Widgets\AdminOnlyWidgetInterface;

/**
 * AdminOnlyRecentMailsWidget.
 *
 * TYPO3 14 only: the marker interface keeps the widget out of the group assignment for non-admins. Registered in Services.php when the interface exists.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AdminOnlyRecentMailsWidget extends RecentMailsWidget implements AdminOnlyWidgetInterface {}
