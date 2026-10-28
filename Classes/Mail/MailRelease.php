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
use KonradMichalik\Typo3Letterbox\Domain\Model\Mail;
use KonradMichalik\Typo3Letterbox\Mbox\{MboxFormat, MessageParser};
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\{Address, RawMessage};
use Throwable;
use TYPO3\CMS\Core\Mail\TransportFactory;

use function is_string;
use function sprintf;
use function strlen;

/**
 * MailRelease.
 *
 * Sends a caught mail on to one allowed address through the delivery transport. Never through core's Mailer, which
 * would hand it to the Letterbox transport again.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MailRelease
{
    public const SENDER_MISSING = 'sender_missing';

    private const MAX_ADDRESS_LENGTH = 254;

    public function __construct(
        private TransportFactory $transportFactory,
        private MboxFormat $mboxFormat,
        private LoggerInterface $logger,
    ) {}

    public function settings(): ReleaseSettings
    {
        return ReleaseSettings::fromMailSettings($this->mailSettings());
    }

    /**
     * Why this mail cannot be released right now, or null when it can.
     */
    public function refusal(Mail $mail): ?string
    {
        return $this->settings()->refusal() ?? (null === $this->sender($mail) ? self::SENDER_MISSING : null);
    }

    /**
     * The first To recipient on the allowlist, to pre-fill the dialog, or an empty string.
     */
    public function suggestedRecipient(Mail $mail): string
    {
        $settings = $this->settings();
        foreach ($mail->to as $address) {
            if ($settings->allows($address->address)) {
                return $address->address;
            }
        }

        return '';
    }

    /**
     * @throws InvalidArgumentException when the recipient is not exactly one valid address on the allowlist
     * @throws RuntimeException         when release is refused or the delivery transport fails
     */
    public function release(Mail $mail, string $recipient, int $backendUserId): void
    {
        $settings = $this->settings();
        $refusal = $settings->refusal();
        $sender = $this->sender($mail);
        if (null !== $refusal || null === $sender) {
            throw new RuntimeException(sprintf('Release is not possible: %s.', $refusal ?? self::SENDER_MISSING), 1761900000);
        }
        $address = $this->recipient($recipient, $settings);

        $mailSettings = $this->mailSettings();
        // A spool would queue the mail for the spool command, which sends through MAIL/transport, that is Letterbox.
        unset($mailSettings['transport_spool_type']);

        try {
            $this->transportFactory
                ->get([...$mailSettings, 'transport' => $settings->delivery])
                ->send(new RawMessage($this->outgoing($mail)), new Envelope($sender, [$address]));
        } catch (Throwable $e) {
            // The exception message may carry addresses or server answers, so only its class is logged.
            $this->logger->warning('Release of mail {id} to a recipient at {domain} failed: {exception}', [
                'id' => $mail->id,
                'domain' => self::domain($address),
                'exception' => $e::class,
            ]);

            throw new RuntimeException('The delivery transport could not send the released mail.', 1761900001, $e);
        }

        $this->logger->info('Released mail {id} to a recipient at {domain} by backend user {uid}', [
            'id' => $mail->id,
            'domain' => self::domain($address),
            'uid' => $backendUserId,
        ]);
    }

    private static function domain(Address $address): string
    {
        return substr($address->getAddress(), (int) strrpos($address->getAddress(), '@') + 1);
    }

    /**
     * The stored message without the envelope header Letterbox put on its first line, with CRLF line endings.
     */
    private function outgoing(Mail $mail): string
    {
        $pattern = '/\A'.preg_quote(MessageParser::ENVELOPE_HEADER, '/').':[^\n]*\n/i';

        return $this->mboxFormat->toCrlf(preg_replace($pattern, '', $mail->source, 1) ?? $mail->source);
    }

    private function recipient(string $value, ReleaseSettings $settings): Address
    {
        // Checked before anything is trimmed, so a line break or a second address can never reach a header or the envelope.
        if ('' === $value || strlen($value) > self::MAX_ADDRESS_LENGTH || 1 === preg_match('/[\x00-\x20\x7F,;<>"()\[\]\\\\]/', $value)
            || false === filter_var($value, \FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('The release address must be exactly one valid address.', 1761900002);
        }
        if (!$settings->allows($value)) {
            throw new InvalidArgumentException('The release address is not allowed by MAIL/transport_letterbox_release.', 1761900003);
        }

        return new Address($value);
    }

    private function sender(Mail $mail): ?Address
    {
        foreach ([$mail->from?->address, $this->mailSettings()['defaultMailFromAddress'] ?? null] as $candidate) {
            if (!is_string($candidate) || '' === $candidate) {
                continue;
            }
            try {
                $address = new Address($candidate);
                // Envelope rejects what SMTP cannot carry as a sender, for example a non-ASCII local part.
                new Envelope($address, [$address]);

                return $address;
            } catch (Throwable) {
                // Not usable as an envelope sender, try the next one.
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function mailSettings(): array
    {
        return (array) ($GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? []);
    }
}
