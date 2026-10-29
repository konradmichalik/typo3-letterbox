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
use Stringable;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Throwable;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * LetterboxTransport.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class LetterboxTransport extends AbstractTransport implements Stringable
{
    private readonly LetterboxSettings $settings;
    private readonly LoggerInterface $logger;

    /**
     * @param array<string, mixed> $mailSettings the MAIL section of TYPO3_CONF_VARS
     */
    public function __construct(
        private readonly array $mailSettings,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        // TransportFactory passes no logger to custom transports.
        $this->logger = $logger ?? GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class);
        parent::__construct($dispatcher, $this->logger);

        $this->settings = LetterboxSettings::fromMailSettings($mailSettings);
        if ('' === $this->settings->mboxFile) {
            throw new InvalidArgumentException('MAIL/transport_mbox_file must be set when using the Letterbox transport.', 1761561613);
        }
    }

    public function __toString(): string
    {
        return 'letterbox://';
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
        $recipients = array_values(array_map(static fn (Address $recipient): string => strtolower($recipient->getAddress()), $message->getEnvelope()->getRecipients()));

        $this->store($message, $recipients);
    }

    /**
     * @param list<string> $recipients
     */
    private function store(SentMessage $message, array $recipients): void
    {
        TransportTakeover::prepareMailbox($this->mailSettings);
        $this->writer()->append(
            $message->getEnvelope()->getSender()->getAddress(),
            new DateTimeImmutable(),
            'X-Letterbox-Envelope-To: '.implode(', ', $recipients)."\r\n".$message->toString(),
        );
        // After append() released the lock: the lock is not re-entrant, pruning takes it again.
        try {
            GeneralUtility::makeInstance(RetentionService::class)->afterAppend($this->settings->mboxFile);
        } catch (Throwable $exception) {
            // The mail is stored. Building the service fails where ext_localconf.php did not run, such as the Install Tool.
            $this->logger->warning('Letterbox could not apply the retention limits: {exception}', ['exception' => $exception::class]);
        }
        if ($this->settings->isMailboxPublic(Environment::getPublicPath(), Environment::getVarPath())) {
            $this->logger->warning('The Letterbox mailbox is inside the public web path, everyone can download it. Move MAIL/transport_mbox_file out of the web root.');
        }
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
