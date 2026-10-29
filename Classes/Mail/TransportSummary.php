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

namespace KonradMichalik\Typo3Letterbox\Mail;

use Symfony\Component\Mailer\Transport\NullTransport;

use function in_array;
use function is_int;
use function is_string;

/**
 * TransportSummary.
 *
 * Names the transport core would use, for the backend: the type, or a DSN as scheme, host and port. User, password,
 * path and query of a DSN never leave this class.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TransportSummary
{
    private const BUILT_IN = ['smtp', 'sendmail', 'mbox', 'null'];

    /**
     * @param array<string, mixed> $mail the MAIL section of TYPO3_CONF_VARS
     */
    public static function of(array $mail): string
    {
        $transport = ltrim(trim((string) ($mail['transport'] ?? '')), '\\');
        $type = strtolower($transport);
        $dsn = trim((string) ($mail['dsn'] ?? ''));

        return match (true) {
            'smtp' === $type => self::withHost('SMTP', trim((string) ($mail['transport_smtp_server'] ?? ''))),
            in_array($type, self::BUILT_IN, true) => $type,
            $type === strtolower(NullTransport::class) => 'null',
            // Core routes every other type to MAIL/dsn when it is set, the order of TransportFactory::get().
            'dsn' === $type || '' !== $dsn => self::dsn($dsn),
            '' === $type => 'sendmail',
            $type === strtolower(LetterboxTransport::class) => 'Letterbox',
            default => $transport,
        };
    }

    private static function dsn(string $dsn): string
    {
        if (1 !== preg_match('/^([a-z][a-z0-9+.-]*):\/\//i', $dsn, $match)) {
            return 'DSN';
        }
        $scheme = strtoupper($match[1]);
        $parts = parse_url($dsn);
        $host = false !== $parts && is_string($parts['host'] ?? null) ? $parts['host'] : '';
        // An unencoded "/", "?" or "#" in the password ends the authority early, and the host would be the user name.
        $userUnparsed = str_contains($dsn, '@') && !isset($parts['user']);
        if ('' === $host || $userUnparsed || 1 !== preg_match('/^(?:[a-z0-9.-]+|\[[0-9a-f:.]+\])$/i', $host)) {
            return $scheme;
        }

        return self::withHost($scheme, $host.(is_int($parts['port'] ?? null) ? ':'.$parts['port'] : ''));
    }

    private static function withHost(string $label, string $host): string
    {
        return '' === $host ? $label : $label.' ('.$host.')';
    }
}
