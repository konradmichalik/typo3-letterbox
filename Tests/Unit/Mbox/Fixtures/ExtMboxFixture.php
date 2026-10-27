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

namespace KonradMichalik\Typo3Letterbox\Tests\Unit\Mbox\Fixtures;

use function sprintf;

/**
 * ExtMboxFixture.
 *
 * Builds a mailbox the way EXT:mbox wrote it: unpadded day in the separator line,
 * local time, CRLF inside the message and one empty line after each mail.
 * Built in code because git may normalise CRLF in a fixture file.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ExtMboxFixture
{
    public const SUBJECTS = ['First', 'Second', 'Third'];

    public static function contents(): string
    {
        $mailbox = '';
        foreach (self::SUBJECTS as $index => $subject) {
            $day = $index + 1;
            $mailbox .= sprintf("From sender@example.org %s Nov %d 10:00:00 2026\n", ['Sun', 'Mon', 'Tue'][$index], $day);
            $mailbox .= sprintf(
                "Date: Sun, %d Nov 2026 10:00:00 +0100\r\nFrom: sender@example.org\r\nTo: rcpt@example.com\r\nSubject: %s\r\nMessage-ID: <ext-%d@example.org>\r\n\r\nBody %d\r\n\n",
                $day,
                $subject,
                $day,
                $day,
            );
        }

        return $mailbox;
    }
}
