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
            DeliveryLoopGuard::loops(TransportTakeover::deliverySettings($mail), $delivery, $sendmailPath ?? (string) ini_get('sendmail_path')) => self::DELIVERY_LOOPS,
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
}
