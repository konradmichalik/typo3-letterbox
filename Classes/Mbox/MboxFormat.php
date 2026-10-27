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

namespace KonradMichalik\Typo3Letterbox\Mbox;

use DateTimeImmutable;

use function sprintf;

/**
 * MboxFormat.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MboxFormat
{
    private const SEPARATOR_PATTERN = '/^From \S+ (?:Mon|Tue|Wed|Thu|Fri|Sat|Sun) (?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) (?: \d|\d{1,2}) \d{2}:\d{2}:\d{2} \d{4}\n?$/';

    public function separatorLine(string $sender, DateTimeImmutable $date): string
    {
        $timestamp = $date->getTimestamp();
        $sender = preg_replace('/\s+/', '', $sender) ?? '';

        return sprintf(
            "From %s %s%2d%s\n",
            '' === $sender ? 'MAILER-DAEMON' : $sender,
            gmdate('D M ', $timestamp),
            (int) gmdate('j', $timestamp),
            gmdate(' H:i:s Y', $timestamp),
        );
    }

    public function encode(string $rfc822): string
    {
        $lf = str_replace(["\r\n", "\r"], "\n", $rfc822);

        // mboxrd: every line of ">"* followed by "From " gains one more ">", so decode() is lossless.
        $escaped = preg_replace('/^(>*From )/m', '>$1', $lf) ?? $lf;

        return rtrim($escaped, "\n")."\n\n";
    }

    public function decode(string $stored): string
    {
        if (str_ends_with($stored, "\n\n")) {
            $stored = substr($stored, 0, -1);
        }

        return preg_replace('/^>(>*From )/m', '$1', $stored) ?? $stored;
    }

    public function isSeparator(string $line): bool
    {
        return 1 === preg_match(self::SEPARATOR_PATTERN, $line);
    }

    public function toCrlf(string $lf): string
    {
        return str_replace("\n", "\r\n", $lf);
    }
}
