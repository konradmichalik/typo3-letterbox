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

/**
 * DeliveryLoopGuard.
 *
 * Whether a delivery transport would hand a mail back to Letterbox. Release and the transport share it, so every new
 * way into letterbox:receive or the mailbox file is caught in one place.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class DeliveryLoopGuard
{
    private const RECEIVE_COMMAND = 'letterbox:receive';
    // Core handles these types itself, every other value is a class name, which core routes to MAIL/dsn when that is set.
    private const BUILT_IN_TYPES = ['smtp', 'sendmail', 'mbox', 'null', 'dsn'];

    /**
     * A sendmail binary or SMTP server that forwards into letterbox:receive on its own cannot be detected here.
     *
     * @param array<string, mixed> $mail         the MAIL section of TYPO3_CONF_VARS
     * @param string               $sendmailPath the php.ini sendmail_path
     */
    public static function loops(array $mail, string $delivery, string $sendmailPath): bool
    {
        $type = strtolower(ltrim(trim($delivery), '\\'));
        // Core's mbox transport writes into transport_mbox_file without separator lines, which is the Letterbox mailbox.
        if ($type === strtolower(LetterboxTransport::class) || 'mbox' === $type) {
            return true;
        }

        if ('sendmail' === $type) {
            // Same precedence as core's TransportFactory.
            return self::runsReceive((string) ($mail['transport_sendmail_command'] ?? $sendmailPath));
        }

        $dsn = (string) ($mail['dsn'] ?? '');
        $usesDsn = 'dsn' === $type || ('' !== $dsn && !in_array($type, self::BUILT_IN_TYPES, true) && $type !== strtolower(NullTransport::class));
        if (!$usesDsn) {
            return false;
        }

        // Symfony's native transport runs the php.ini sendmail_path.
        return self::runsReceive(rawurldecode($dsn)) || (str_contains(strtolower($dsn), 'native://') && self::runsReceive($sendmailPath));
    }

    private static function runsReceive(string $command): bool
    {
        return str_contains(strtolower($command), self::RECEIVE_COMMAND);
    }
}
