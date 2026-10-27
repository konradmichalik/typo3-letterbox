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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\Mail\Fixtures;

use Stringable;
use Symfony\Component\Mailer\{Envelope, SentMessage};
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * RecordingTransport.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RecordingTransport implements TransportInterface, Stringable
{
    /**
     * @var list<list<string>>
     */
    public static array $recipients = [];

    /**
     * @var list<string>
     */
    public static array $messages = [];

    /**
     * @param array<string, mixed> $mailSettings
     */
    public function __construct(array $mailSettings = [])
    {
        unset($mailSettings);
    }

    public function __toString(): string
    {
        return 'recording://';
    }

    public static function reset(): void
    {
        self::$recipients = [];
        self::$messages = [];
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
    {
        $envelope ??= Envelope::create($message);
        self::$recipients[] = array_values(array_map(static fn ($address): string => $address->getAddress(), $envelope->getRecipients()));

        self::$messages[] = $message->toString();

        return new SentMessage($message, $envelope);
    }
}
