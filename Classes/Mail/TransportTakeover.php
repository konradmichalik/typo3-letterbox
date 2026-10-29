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

use TYPO3\CMS\Core\Utility\GeneralUtility;

use function dirname;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;

/**
 * TransportTakeover.
 *
 * Makes Letterbox the mail transport when the extension setting `enable` or LETTERBOX_ENABLE says so. It runs in
 * ext_localconf.php, after settings.php and additional.php and before any mailer is built. The original transport
 * and DSN are kept for the delivery of mails that are not caught, at runtime only.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TransportTakeover
{
    public const KEY = 'transport_letterbox_takeover';
    public const ENVIRONMENT_VARIABLE = 'LETTERBOX_ENABLE';
    public const BY_SETTING = 'setting';
    public const BY_ENVIRONMENT = 'env';

    private const ON = ['1', 'true', 'on'];
    private const OFF = ['0', 'false', 'off'];
    private const MAILBOX = '/letterbox/mails.mbox';

    /**
     * Who switches Letterbox on, or null when it stays off. A recognised environment value wins over the setting.
     */
    public static function source(string $environment, mixed $setting): ?string
    {
        $value = strtolower(trim($environment));
        if (in_array($value, self::ON, true)) {
            return self::BY_ENVIRONMENT;
        }
        if (in_array($value, self::OFF, true)) {
            return null;
        }

        return true === $setting || '1' === (string) (is_scalar($setting) ? $setting : '') ? self::BY_SETTING : null;
    }

    /**
     * @param array<string, mixed> $mail the MAIL section of TYPO3_CONF_VARS
     *
     * @return array<string, mixed>
     */
    public static function apply(array $mail, bool $enabled, string $source, string $varPath): array
    {
        if (!$enabled || LetterboxTransport::isSelectedIn($mail)) {
            return $mail;
        }

        $transport = trim((string) ($mail['transport'] ?? ''));
        $dsn = trim((string) ($mail['dsn'] ?? ''));
        $takenOver = [
            ...$mail,
            'transport' => LetterboxTransport::class,
            'dsn' => '',
            self::KEY => ['by' => $source, 'transport' => $transport, 'dsn' => $dsn],
        ];
        if ('' === trim((string) ($mail['transport_mbox_file'] ?? ''))) {
            $takenOver['transport_mbox_file'] = rtrim($varPath, '/').self::MAILBOX;
            $takenOver[self::KEY]['mbox_file'] = $takenOver['transport_mbox_file'];
        }
        $delivery = '' !== $transport ? $transport : ('' !== $dsn ? 'dsn' : '');
        if ('' === trim((string) ($mail['transport_letterbox_delivery'] ?? '')) && '' !== $delivery) {
            $takenOver['transport_letterbox_delivery'] = $delivery;
        }

        return $takenOver;
    }

    /**
     * @param array<string, mixed> $mail
     *
     * @return array{by: string, transport: string, dsn: string}|null
     */
    public static function of(array $mail): ?array
    {
        $takeover = $mail[self::KEY] ?? null;
        if (!is_array($takeover) || !is_string($takeover['by'] ?? null)) {
            return null;
        }

        return ['by' => $takeover['by'], 'transport' => (string) ($takeover['transport'] ?? ''), 'dsn' => (string) ($takeover['dsn'] ?? '')];
    }

    /**
     * The MAIL settings as they were before the takeover, or null without one.
     *
     * @param array<string, mixed> $mail
     *
     * @return array<string, mixed>|null
     */
    public static function original(array $mail): ?array
    {
        $takeover = self::of($mail);

        return null === $takeover ? null : [...$mail, 'transport' => $takeover['transport'], 'dsn' => $takeover['dsn']];
    }

    /**
     * The mailbox path the takeover filled in, or an empty string when the path was configured.
     *
     * @param array<string, mixed> $mail
     */
    public static function defaultMailbox(array $mail): string
    {
        $takeover = $mail[self::KEY] ?? null;

        return is_array($takeover) ? (string) ($takeover['mbox_file'] ?? '') : '';
    }

    /**
     * Creates the directory of the default mailbox below the var path, which does not exist before the first mail.
     *
     * @param array<string, mixed> $mail
     */
    public static function prepareMailbox(array $mail): void
    {
        $path = self::defaultMailbox($mail);
        if ('' !== $path && !is_dir(dirname($path))) {
            GeneralUtility::mkdir_deep(dirname($path));
        }
    }

    /**
     * The settings to build the delivery transport from: with the original DSN, so delivery works as before the takeover.
     *
     * @param array<string, mixed> $mail
     *
     * @return array<string, mixed>
     */
    public static function deliverySettings(array $mail): array
    {
        $takeover = self::of($mail);

        return null === $takeover ? $mail : [...$mail, 'dsn' => $takeover['dsn']];
    }
}
