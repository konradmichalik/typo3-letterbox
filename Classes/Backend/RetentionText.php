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

use KonradMichalik\Typo3Letterbox\Mail\RetentionSettings;

use function sprintf;

/**
 * RetentionText.
 *
 * Phrases the retention limits for the inbox meta line, with singular labels for a limit of one.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RetentionText
{
    /**
     * @param callable(string): string $translate label key to text
     *
     * @return string empty when neither limit is set
     */
    public static function of(RetentionSettings $retention, callable $translate): string
    {
        $mails = 1 === $retention->maxMessages
            ? $translate('inbox.retention.mails.one')
            : sprintf($translate('inbox.retention.mails'), $retention->maxMessages);
        $days = 1 === $retention->maxAgeDays
            ? $translate('inbox.retention.days.one')
            : sprintf($translate('inbox.retention.days'), $retention->maxAgeDays);

        return match (true) {
            $retention->maxMessages > 0 && $retention->maxAgeDays > 0 => sprintf($translate('inbox.retention'), $mails, $days),
            $retention->maxMessages > 0 => sprintf($translate('inbox.retention.messages'), $mails),
            $retention->maxAgeDays > 0 => sprintf($translate('inbox.retention.age'), $days),
            default => '',
        };
    }
}
