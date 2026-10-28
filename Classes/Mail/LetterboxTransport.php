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

use DateTimeImmutable;
use InvalidArgumentException;
use KonradMichalik\Typo3Letterbox\Mbox\{MailboxLock, MailboxWriter, MboxFormat};
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Symfony\Component\Mailer\{Envelope, SentMessage};
use Symfony\Component\Mailer\Transport\AbstractTransport;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Mail\TransportFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function sprintf;

/**
 * LetterboxTransport.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class LetterboxTransport extends AbstractTransport implements Stringable
{
    private readonly LetterboxSettings $settings;

    /**
     * @param array<string, mixed> $mailSettings the MAIL section of TYPO3_CONF_VARS
     */
    public function __construct(
        private readonly array $mailSettings,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        // TransportFactory passes no logger to custom transports.
        parent::__construct($dispatcher, $logger ?? GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class));

        $this->settings = LetterboxSettings::fromMailSettings($mailSettings, (string) Environment::getContext());
        if ('' === $this->settings->mboxFile) {
            throw new InvalidArgumentException('MAIL/transport_mbox_file must be set when using the Letterbox transport.', 1761561613);
        }
    }

    public function __toString(): string
    {
        return 'letterbox://'.$this->settings->mode->value;
    }

    /**
     * @param array<string, mixed> $mailSettings the MAIL section of TYPO3_CONF_VARS
     */
    public static function isSelectedIn(array $mailSettings): bool
    {
        return 0 === strcasecmp(ltrim((string) ($mailSettings['transport'] ?? ''), '\\'), self::class);
    }

    protected function doSend(SentMessage $message): void
    {
        $envelope = $message->getEnvelope();
        $caught = [];
        $delivered = [];
        foreach ($envelope->getRecipients() as $recipient) {
            if ($this->isCaught($recipient->getAddress())) {
                $caught[] = strtolower($recipient->getAddress());
            } else {
                $delivered[] = $recipient;
            }
        }

        if ([] !== $delivered && '' === $this->settings->delivery) {
            throw new RuntimeException(sprintf('Letterbox needs MAIL/transport_letterbox_delivery for mode %s.', $this->settings->mode->value), 1761561614);
        }
        if ([] !== $delivered && $this->settings->deliveryLoops) {
            throw new RuntimeException(sprintf('MAIL/transport_letterbox_delivery "%s" hands mails back to Letterbox, refusing to deliver in mode %s.', $this->settings->delivery, $this->settings->mode->value), 1761561615);
        }

        if ([] !== $caught) {
            $this->writer()->append(
                $envelope->getSender()->getAddress(),
                new DateTimeImmutable(),
                'X-Letterbox-Envelope-To: '.implode(', ', $caught)."\r\n".$message->toString(),
            );
            // After append() released the lock: the lock is not re-entrant, pruning takes it again.
            GeneralUtility::makeInstance(RetentionService::class)->afterAppend($this->settings->mboxFile);
        }

        if ([] !== $delivered) {
            GeneralUtility::makeInstance(TransportFactory::class)
                ->get([...$this->mailSettings, 'transport' => $this->settings->delivery])
                ->send($message->getOriginalMessage(), new Envelope($envelope->getSender(), $delivered));
        }
    }

    private function isCaught(string $address): bool
    {
        if (DeliveryMode::All === $this->settings->mode) {
            return true;
        }
        if (DeliveryMode::Off === $this->settings->mode) {
            return false;
        }
        foreach ($this->settings->catch as $pattern) {
            if ($pattern->matches($address)) {
                return true;
            }
        }

        return false;
    }

    private function writer(): MailboxWriter
    {
        return new MailboxWriter(
            $this->settings->mboxFile,
            new MboxFormat(),
            new MailboxLock(GeneralUtility::makeInstance(LockFactory::class)),
        );
    }
}
