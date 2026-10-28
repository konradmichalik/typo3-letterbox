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

use InvalidArgumentException;
use Symfony\Component\Mailer\Transport\NullTransport;

use function in_array;
use function ini_get;

/**
 * ReleaseSettings.
 *
 * Release settings: the allowlist in MAIL/transport_letterbox_release and whether the delivery transport may be used
 * for a release. Anything unclear switches release off.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ReleaseSettings
{
    public const OFF = 'release_off';
    public const INVALID = 'release_invalid';
    public const DELIVERY_MISSING = 'delivery_missing';
    public const DELIVERY_LOOPS = 'delivery_loops';

    private const RECEIVE_COMMAND = 'letterbox:receive';
    // Core handles these types itself, every other value is a class name, which core routes to MAIL/dsn when that is set.
    private const BUILT_IN_TYPES = ['smtp', 'sendmail', 'mbox', 'null', 'dsn'];

    /**
     * @param list<RecipientPattern> $allowlist
     */
    private function __construct(
        public array $allowlist,
        public string $delivery,
        private ?string $refusal,
    ) {}

    /**
     * @param array<string, mixed> $mail         the MAIL section of TYPO3_CONF_VARS
     * @param string|null          $sendmailPath the php.ini sendmail_path, read from the runtime when null
     */
    public static function fromMailSettings(array $mail, ?string $sendmailPath = null): self
    {
        $delivery = trim((string) ($mail['transport_letterbox_delivery'] ?? ''));
        try {
            $allowlist = RecipientPattern::listFrom($mail['transport_letterbox_release'] ?? []);
        } catch (InvalidArgumentException) {
            return new self([], $delivery, self::INVALID);
        }

        $refusal = match (true) {
            [] === $allowlist => self::OFF,
            '' === $delivery => self::DELIVERY_MISSING,
            self::loops($mail, $delivery, $sendmailPath ?? (string) ini_get('sendmail_path')) => self::DELIVERY_LOOPS,
            default => null,
        };

        return new self(null === $refusal ? $allowlist : [], $delivery, $refusal);
    }

    public function isEnabled(): bool
    {
        return null === $this->refusal;
    }

    public function refusal(): ?string
    {
        return $this->refusal;
    }

    public function allows(string $address): bool
    {
        foreach ($this->allowlist as $pattern) {
            if ($pattern->matches($address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the delivery would hand the mail back to Letterbox. A sendmail binary or SMTP server that forwards into
     * letterbox:receive on its own cannot be detected here.
     *
     * @param array<string, mixed> $mail
     */
    private static function loops(array $mail, string $delivery, string $sendmailPath): bool
    {
        $type = strtolower(ltrim($delivery, '\\'));
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
